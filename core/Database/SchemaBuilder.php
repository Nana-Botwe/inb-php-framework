<?php

declare(strict_types=1);

namespace Core\Database;

use Closure;
use Core\Database;

/**
 * Creates and changes tables on one connection, using that database's grammar.
 * Use it through the Schema facade: Schema::create(...), Schema::connection('reporting')->create(...).
 */
final class SchemaBuilder
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->run($this->db->grammar()->compileCreate($blueprint));
    }

    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->run($this->db->grammar()->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->run([$this->db->grammar()->compileDrop($table)]);
    }

    public function dropIfExists(string $table): void
    {
        $this->run([$this->db->grammar()->compileDropIfExists($table)]);
    }

    public function rename(string $from, string $to): void
    {
        $this->run([$this->db->grammar()->compileRename($from, $to)]);
    }

    public function hasTable(string $table): bool
    {
        return in_array(strtolower($table), array_map('strtolower', $this->getTables()), true);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', $this->getColumns($table)), true);
    }

    /** @return string[] */
    public function getTables(): array
    {
        [$sql, $bindings, $key] = $this->db->grammar()->compileTables();

        return array_map(static fn (array $row) => (string) ($row[$key] ?? reset($row)), $this->db->select($sql, $bindings));
    }

    /** @return string[] */
    public function getColumns(string $table): array
    {
        [$sql, $bindings, $key] = $this->db->grammar()->compileColumns($table);

        return array_map(static fn (array $row) => (string) ($row[$key] ?? reset($row)), $this->db->select($sql, $bindings));
    }

    /** Drop every table (used by migrate:fresh). */
    public function dropAllTables(): void
    {
        $tables = $this->getTables();

        $this->withoutForeignKeyConstraints(function () use ($tables): void {
            $this->run($this->db->grammar()->compileDropAllTables($tables));
        });
    }

    public function withoutForeignKeyConstraints(Closure $callback): void
    {
        $grammar = $this->db->grammar();
        $disable = $grammar->compileDisableForeignKeys();
        if ($disable !== null) {
            $this->db->pdo()->exec($disable);
        }

        try {
            $callback();
        } finally {
            $enable = $grammar->compileEnableForeignKeys();
            if ($enable !== null) {
                $this->db->pdo()->exec($enable);
            }
        }
    }

    /** @param string[] $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->db->pdo()->exec($sql);
        }
    }

    public function connection(): Database
    {
        return $this->db;
    }
}
