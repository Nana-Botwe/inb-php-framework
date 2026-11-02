<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

use Core\Database\ColumnDefinition;

/** MySQL 5.7+ and MariaDB 10.3+ */
class MySqlGrammar extends Grammar
{
    protected string $quoteOpen = '`';

    protected string $quoteClose = '`';

    public function name(): string
    {
        return 'mysql';
    }

    /** MySQL treats backslash as an escape character inside strings too. */
    public function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrders): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ' LIMIT ' . ($limit ?? '18446744073709551615') . ($offset !== null ? ' OFFSET ' . $offset : '');
    }

    public function random(): string
    {
        return 'RAND()';
    }

    /** Backslash is MySQL's default LIKE escape character. */
    public function likeEscape(): string
    {
        return '';
    }

    public function sessionStatements(): array
    {
        // Reject invalid/too-long data instead of silently truncating it.
        return ["SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'"];
    }

    public function compileTables(): array
    {
        return ["SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'", [], 'name'];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table], 'name'];
    }

    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    protected function compileAddColumn(string $table, ColumnDefinition $column): string
    {
        return "ALTER TABLE $table ADD COLUMN " . $this->compileColumn($column)
            . ($column->after !== null ? ' AFTER ' . $this->wrap($column->after) : '');
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name) . " ON $table";
    }

    protected function compileDropForeign(string $table, string $name): string
    {
        return "ALTER TABLE $table DROP FOREIGN KEY " . $this->wrap($name);
    }

    public function compileDisableForeignKeys(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS = 0';
    }

    public function compileEnableForeignKeys(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS = 1';
    }

    protected function autoIncrementType(ColumnDefinition $column): string
    {
        return ($column->type === 'bigInteger' ? 'BIGINT' : 'INT') . ' UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function unsignedSuffix(ColumnDefinition $column): string
    {
        return $column->unsigned && in_array($column->type, ['integer', 'tinyInteger', 'smallInteger', 'bigInteger', 'decimal', 'float', 'double'], true)
            ? ' UNSIGNED' : '';
    }

    protected function columnExtras(ColumnDefinition $column): string
    {
        return ($column->useCurrentOnUpdate ? ' ON UPDATE CURRENT_TIMESTAMP' : '')
            . ($column->comment !== null ? ' COMMENT ' . $this->quoteString($column->comment) : '');
    }

    protected function enumUsesCheck(): bool
    {
        return false;
    }

    public function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'integer' => 'INT',
            'tinyInteger' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'boolean' => 'TINYINT(1)',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'dateTime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'year' => 'YEAR',
            'json' => 'JSON',
            'enum' => 'ENUM(' . implode(', ', array_map(fn ($v) => $this->quoteString((string) $v), $o['allowed'])) . ')',
            default => parent::type($column),
        };
    }
}
