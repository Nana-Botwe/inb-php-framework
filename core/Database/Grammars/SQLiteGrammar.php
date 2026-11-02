<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

use Core\Database\Blueprint;
use Core\Database\ColumnDefinition;
use RuntimeException;

/** SQLite 3.35+ */
class SQLiteGrammar extends Grammar
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrders): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ' LIMIT ' . ($limit ?? -1) . ($offset !== null ? ' OFFSET ' . $offset : '');
    }

    public function sessionStatements(): array
    {
        return ['PRAGMA foreign_keys = ON'];
    }

    public function compileTables(): array
    {
        return ["SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name", [], 'name'];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT name FROM pragma_table_info(?)', [$table], 'name'];
    }

    public function compileCreate(Blueprint $blueprint): array
    {
        return parent::compileCreate($blueprint);
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        if ($blueprint->foreignKeys !== []) {
            throw new RuntimeException('SQLite cannot add foreign keys to an existing table. Define them in Schema::create().');
        }
        foreach ($blueprint->commands as [$command]) {
            if ($command === 'dropForeign') {
                throw new RuntimeException('SQLite cannot drop foreign keys.');
            }
        }

        return parent::compileAlter($blueprint);
    }

    protected function compileAddColumn(string $table, ColumnDefinition $column): string
    {
        return "ALTER TABLE $table ADD COLUMN " . $this->compileColumn($column);
    }

    /** SQLite doesn't support named constraints on inline foreign keys the same way; keep it simple. */
    public function compileForeign(\Core\Database\ForeignKeyDefinition $fk): string
    {
        return 'FOREIGN KEY (' . $this->wrap($fk->column) . ') REFERENCES ' . $this->wrap($fk->on)
            . ' (' . $this->wrap($fk->references) . ')' . $this->foreignActions($fk);
    }

    public function compileDisableForeignKeys(): ?string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    public function compileEnableForeignKeys(): ?string
    {
        return 'PRAGMA foreign_keys = ON';
    }

    protected function autoIncrementType(ColumnDefinition $column): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'integer', 'tinyInteger', 'smallInteger', 'bigInteger', 'boolean', 'year' => 'INTEGER',
            'decimal' => sprintf('NUMERIC(%d,%d)', $o['precision'], $o['scale']),
            'float', 'double' => 'REAL',
            'dateTime', 'timestamp' => 'DATETIME',
            default => parent::type($column),
        };
    }
}
