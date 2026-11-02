<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

use Core\Database\Blueprint;
use Core\Database\ColumnDefinition;
use Core\Database\ForeignKeyDefinition;
use InvalidArgumentException;
use RuntimeException;

/**
 * SQL dialect. The base class speaks standard SQL:2008 (identity columns, OFFSET/FETCH, double-quoted
 * identifiers); each database overrides only what it does differently. Grammars never need a live
 * connection, so the SQL they produce can be unit-tested for every database.
 */
abstract class Grammar
{
    protected string $quoteOpen = '"';

    protected string $quoteClose = '"';

    /** Database family name: mysql, pgsql, sqlite, sqlsrv, oci, firebird, odbc */
    abstract public function name(): string;

    // ================================================================== identifiers & values

    /** Safely quote a table/column name; anything that isn't a plain identifier is rejected. */
    public function wrap(string $name): string
    {
        if ($name === '*') {
            return '*';
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier [$name].");
        }

        return implode('.', array_map(fn ($part) => $this->quoteOpen . $part . $this->quoteClose, explode('.', $name)));
    }

    /** Literal for DDL defaults (never used for user data — that is always bound). */
    public function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function literal(mixed $value, ?ColumnDefinition $column = null): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->quoteString((string) $value),
        };
    }

    // ================================================================== queries

    public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrders): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ' OFFSET ' . (int) $offset . ' ROWS' . ($limit !== null ? ' FETCH NEXT ' . $limit . ' ROWS ONLY' : '');
    }

    public function random(): string
    {
        return 'RANDOM()';
    }

    public function likeOperator(): string
    {
        return 'LIKE';
    }

    /** Escape clause so "%" and "_" typed by users are matched literally. */
    public function likeEscape(): string
    {
        return " ESCAPE '\\'";
    }

    /** Alias syntax for tables (Oracle forbids "AS"). */
    public function tableAlias(string $table, string $alias): string
    {
        return $this->wrap($table) . ' ' . $this->wrap($alias);
    }

    /**
     * How to get the new ID after INSERT: lastInsertId | returning | output | returning_into
     */
    public function insertIdStrategy(): string
    {
        return 'lastInsertId';
    }

    public function compileInsertGetId(string $table, string $columns, string $values, string $key): string
    {
        return match ($this->insertIdStrategy()) {
            'returning' => "INSERT INTO $table ($columns) VALUES ($values) RETURNING " . $this->wrap($key),
            'output' => "INSERT INTO $table ($columns) OUTPUT INSERTED." . $this->wrap($key) . " VALUES ($values)",
            'returning_into' => "INSERT INTO $table ($columns) VALUES ($values) RETURNING " . $this->wrap($key) . ' INTO :__insert_id',
            default => "INSERT INTO $table ($columns) VALUES ($values)",
        };
    }

    /** Statements run right after connecting. @return string[] */
    public function sessionStatements(): array
    {
        return [];
    }

    // ================================================================== schema: inspection

    /** @return array{0: string, 1: array<int, mixed>, 2: string} sql, bindings, column holding the name */
    public function compileTables(): array
    {
        return ["SELECT table_name AS name FROM information_schema.tables WHERE table_type = 'BASE TABLE'", [], 'name'];
    }

    /** @return array{0: string, 1: array<int, mixed>, 2: string} */
    public function compileColumns(string $table): array
    {
        return ['SELECT column_name AS name FROM information_schema.columns WHERE table_name = ?', [$table], 'name'];
    }

    // ================================================================== schema: DDL

    /** @return string[] */
    public function compileCreate(Blueprint $blueprint): array
    {
        $parts = array_map(fn (ColumnDefinition $c) => $this->compileColumn($c), $blueprint->columns);

        foreach ($blueprint->indexes as $index) {
            if ($index['type'] === 'primary') {
                $parts[] = 'PRIMARY KEY (' . $this->columnize($index['columns']) . ')';
            }
        }

        foreach ($blueprint->foreignKeys as $fk) {
            $parts[] = $this->compileForeign($fk);
        }

        $sql = 'CREATE TABLE ' . $this->wrap($blueprint->table) . " (\n    " . implode(",\n    ", $parts) . "\n)" . $this->tableSuffix();

        return [$sql, ...$this->compileIndexes($blueprint)];
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    /** @return string[] */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->wrap($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns as $column) {
            $statements[] = $this->compileAddColumn($table, $column);
        }

        foreach ($blueprint->foreignKeys as $fk) {
            $statements[] = "ALTER TABLE $table ADD " . $this->compileForeign($fk);
        }

        foreach ($blueprint->commands as [$command, $name]) {
            $statements[] = match ($command) {
                'dropColumn' => $this->compileDropColumn($table, $name),
                'dropIndex' => $this->compileDropIndex($table, $name),
                'dropForeign' => $this->compileDropForeign($table, $name),
            };
        }

        return [...$statements, ...$this->compileIndexes($blueprint)];
    }

    protected function compileAddColumn(string $table, ColumnDefinition $column): string
    {
        return "ALTER TABLE $table ADD " . $this->compileColumn($column);
    }

    protected function compileDropColumn(string $table, string $column): string
    {
        return "ALTER TABLE $table DROP COLUMN " . $this->wrap($column);
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name);
    }

    protected function compileDropForeign(string $table, string $name): string
    {
        return "ALTER TABLE $table DROP CONSTRAINT " . $this->wrap($name);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrap($table);
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrap($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($from) . ' RENAME TO ' . $this->wrap($to);
    }

    /** Statements to drop every table (used by migrate:fresh). @param string[] $tables @return string[] */
    public function compileDropAllTables(array $tables): array
    {
        return array_map(fn ($t) => $this->compileDropIfExists($t), $tables);
    }

    public function compileDisableForeignKeys(): ?string
    {
        return null;
    }

    public function compileEnableForeignKeys(): ?string
    {
        return null;
    }

    /** @return string[] */
    protected function compileIndexes(Blueprint $blueprint): array
    {
        $statements = [];
        foreach ($blueprint->indexes as $index) {
            if ($index['type'] === 'primary') {
                continue;
            }
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['type'] === 'unique' ? 'UNIQUE ' : '',
                $this->wrap($index['name']),
                $this->wrap($blueprint->table),
                $this->columnize($index['columns']),
            );
        }

        return $statements;
    }

    /** Column definition: name TYPE [DEFAULT x] NULL|NOT NULL [extras]. DEFAULT before NULL works everywhere. */
    public function compileColumn(ColumnDefinition $column): string
    {
        if ($column->autoIncrement) {
            return $this->wrap($column->name) . ' ' . $this->autoIncrementType($column);
        }

        $sql = $this->wrap($column->name) . ' ' . $this->type($column) . $this->unsignedSuffix($column);

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default, $column);
        }

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        $sql .= $this->columnExtras($column);

        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }

        if ($column->type === 'enum' && $this->enumUsesCheck()) {
            $allowed = implode(', ', array_map(fn ($v) => $this->quoteString((string) $v), $column->options['allowed']));
            $sql .= ' CHECK (' . $this->wrap($column->name) . " IN ($allowed))";
        }

        return $sql;
    }

    protected function autoIncrementType(ColumnDefinition $column): string
    {
        $type = $column->type === 'bigInteger' ? 'BIGINT' : 'INTEGER';

        return "$type GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY";
    }

    protected function unsignedSuffix(ColumnDefinition $column): string
    {
        return '';
    }

    protected function columnExtras(ColumnDefinition $column): string
    {
        return '';
    }

    protected function enumUsesCheck(): bool
    {
        return true;
    }

    public function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'string' => 'VARCHAR(' . (int) $o['length'] . ')',
            'char' => 'CHAR(' . (int) $o['length'] . ')',
            'text', 'mediumText', 'longText' => 'TEXT',
            'integer' => 'INTEGER',
            'tinyInteger', 'smallInteger' => 'SMALLINT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'decimal' => sprintf('DECIMAL(%d,%d)', $o['precision'], $o['scale']),
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'date' => 'DATE',
            'dateTime', 'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'SMALLINT',
            'json' => 'TEXT',
            'enum' => 'VARCHAR(255)',
            default => throw new RuntimeException("Unknown column type [{$column->type}]."),
        };
    }

    public function compileForeign(ForeignKeyDefinition $fk): string
    {
        if ($fk->on === '') {
            throw new RuntimeException("Foreign key on [{$fk->column}] needs ->on('table').");
        }

        return 'CONSTRAINT ' . $this->wrap($fk->name) . ' FOREIGN KEY (' . $this->wrap($fk->column) . ') REFERENCES '
            . $this->wrap($fk->on) . ' (' . $this->wrap($fk->references) . ')' . $this->foreignActions($fk);
    }

    protected function foreignActions(ForeignKeyDefinition $fk): string
    {
        return ' ON DELETE ' . $fk->onDelete . ' ON UPDATE ' . $fk->onUpdate;
    }

    /** @param string[] $columns */
    protected function columnize(array $columns): string
    {
        return implode(', ', array_map(fn ($c) => $this->wrap($c), $columns));
    }
}
