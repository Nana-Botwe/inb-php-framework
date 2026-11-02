<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config;
use Core\Console\Command;
use Core\Database;
use RuntimeException;

/**
 * Restore a backup made by db:backup. Destructive: requires --force.
 *   php console db:restore storage/backups/mysql-20261020-020000.sql.gz --force
 */
final class DbRestoreCommand extends Command
{
    protected string $signature = 'db:restore {file} {connection?} {--force}';

    protected string $description = 'Restore a db:backup file (replaces current data; needs --force)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $file = storage_path('backups/' . basename($file));
        }
        if (!is_file($file)) {
            $this->error('Backup file not found.');

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $this->warn('This REPLACES the current database with ' . basename($file) . '. Take a fresh backup first, then add --force.');

            return self::FAILURE;
        }

        $name = $this->argument('connection');
        $db = $name === null ? Database::instance() : Database::connection($name);
        $config = $db->getConfig();
        $password = $db->passwordForExternalTool();

        match ($db->driver()) {
            'mysql' => $this->pipe($file, [(string) Config::get('backup.mysql', 'mysql'), '--host=' . ($config['host'] ?? '127.0.0.1'), '--port=' . ($config['port'] ?? 3306), '--user=' . ($config['username'] ?? ''), (string) ($config['database'] ?? '')], ['MYSQL_PWD' => $password]),
            'pgsql' => $this->pipe($file, [(string) Config::get('backup.psql', 'psql'), '--host=' . ($config['host'] ?? '127.0.0.1'), '--port=' . ($config['port'] ?? 5432), '--username=' . ($config['username'] ?? ''), '--dbname=' . ($config['database'] ?? ''), '--set=ON_ERROR_STOP=1'], ['PGPASSWORD' => $password]),
            'sqlite' => $this->sqlite($db, $file),
            default => throw new RuntimeException('Use the database\'s own restore tool for ' . $db->driver() . '.'),
        };

        $this->info('Restored ' . basename($file) . ' into [' . $db->getName() . '].');

        return self::SUCCESS;
    }

    /** @param string[] $command @param array<string, string> $env */
    private function pipe(string $file, array $command, array $env): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0] . '.');
        }

        $in = gzopen($file, 'rb');
        while (!gzeof($in)) {
            fwrite($pipes[0], (string) gzread($in, 1 << 16));
        }
        gzclose($in);
        fclose($pipes[0]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException($command[0] . ' failed: ' . trim((string) $errors));
        }
    }

    private function sqlite(Database $db, string $file): void
    {
        $target = (string) ($db->getConfig()['database'] ?? '');
        if ($target === '' || $target === ':memory:') {
            throw new RuntimeException('The SQLite connection has no database file to restore into.');
        }

        Database::purge();
        $in = gzopen($file, 'rb');
        $out = fopen($target . '.restore', 'wb');
        while (!gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 16));
        }
        gzclose($in);
        fclose($out);
        if (!rename($target . '.restore', $target)) {
            throw new RuntimeException('Could not replace the SQLite file (is it in use?).');
        }
    }
}
