<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Database;
use RuntimeException;

/**
 * Runs migrations in batches so they can be rolled back:
 *   migrate · migrate:rollback · migrate:reset · migrate:fresh · migrate:status
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    public function __construct(private readonly string $path, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /** @return string[] Names of migrations that ran. */
    public function run(): array
    {
        $this->ensureRepository();
        $ran = $this->ranMigrations();
        $pending = array_values(array_diff(array_keys($this->files()), $ran));

        if ($pending === []) {
            ($this->output)('Nothing to migrate.');

            return [];
        }

        $batch = $this->lastBatch() + 1;
        foreach ($pending as $name) {
            ($this->output)("Migrating: $name");
            $this->resolve($name)->up();
            $this->db()->execute('INSERT INTO migrations (migration, batch) VALUES (?, ?)', [$name, $batch]);
            ($this->output)("Migrated:  $name");
        }

        return $pending;
    }

    /** @return string[] Names rolled back. */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $rows = $this->db()->select(
            'SELECT migration FROM migrations WHERE batch > ? ORDER BY batch DESC, id DESC',
            [max(0, $this->lastBatch() - $steps)],
        );

        if ($rows === []) {
            ($this->output)('Nothing to roll back.');

            return [];
        }

        $names = array_column($rows, 'migration');
        foreach ($names as $name) {
            ($this->output)("Rolling back: $name");
            $this->resolve($name)->down();
            $this->db()->execute('DELETE FROM migrations WHERE migration = ?', [$name]);
            ($this->output)("Rolled back:  $name");
        }

        return $names;
    }

    public function reset(): void
    {
        $this->rollback(PHP_INT_MAX);
    }

    public function fresh(): void
    {
        Schema::dropAllTables();
        ($this->output)('Dropped all tables.');
        $this->run();
    }

    /** @return array<string, string|null> migration => 'Ran (batch N)' or null when pending */
    public function status(): array
    {
        $this->ensureRepository();
        $batches = [];
        foreach ($this->db()->select('SELECT migration, batch FROM migrations') as $row) {
            $batches[$row['migration']] = (int) $row['batch'];
        }

        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[$name] = isset($batches[$name]) ? 'Ran (batch ' . $batches[$name] . ')' : null;
        }

        return $status;
    }

    /** @return array<string, string> name => file path, sorted */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }
        ksort($files);

        return $files;
    }

    private function resolve(string $name): Migration
    {
        $file = $this->files()[$name] ?? throw new RuntimeException("Migration file [$name] not found.");
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("[$name] must return an instance of " . Migration::class . '.');
        }

        return $migration;
    }

    private function ensureRepository(): void
    {
        if (Schema::hasTable('migrations')) {
            return;
        }

        Schema::create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    /** @return string[] */
    private function ranMigrations(): array
    {
        return array_column($this->db()->select('SELECT migration FROM migrations ORDER BY batch, id'), 'migration');
    }

    private function lastBatch(): int
    {
        return (int) ($this->db()->selectOne('SELECT MAX(batch) AS batch FROM migrations')['batch'] ?? 0);
    }

    private function db(): Database
    {
        return Database::instance();
    }
}
