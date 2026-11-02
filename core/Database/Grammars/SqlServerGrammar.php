<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

use Core\Database\ColumnDefinition;
use Core\Database\ForeignKeyDefinition;

/** Microsoft SQL Server 2016+ / Azure SQL */
class SqlServerGrammar extends Grammar
{
    protected string $quoteOpen = '[';

    protected string $quoteClose = ']';

    public function name(): string
    {
        return 'sqlsrv';
    }

    /** OFFSET/FETCH requires an ORDER BY in SQL Server. */
    public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrders): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ($hasOrders ? '' : ' ORDER BY (SELECT 0)') . parent::compileLimitOffset($limit, $offset, $hasOrders);
    }

    public function random(): string
    {
        return 'NEWID()';
    }

    public function insertIdStrategy(): string
    {
        return 'output';
    }

    public function compileTables(): array
    {
        return ["SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = SCHEMA_NAME() ORDER BY TABLE_NAME", [], 'name'];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table], 'name'];
    }

    public function compileRename(string $from, string $to): string
    {
        return 'EXEC sp_rename ' . $this->quoteString($from) . ', ' . $this->quoteString($to);
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name) . " ON $table";
    }

    /** Foreign keys must go before the tables they point to; drop every constraint first. */
    public function compileDropAllTables(array $tables): array
    {
        $dropConstraints = "DECLARE @sql NVARCHAR(MAX) = N''; "
            . "SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) + N'.' + QUOTENAME(OBJECT_NAME(parent_object_id)) "
            . "+ N' DROP CONSTRAINT ' + QUOTENAME(name) + N'; ' FROM sys.foreign_keys; EXEC sp_executesql @sql;";

        return [$dropConstraints, ...parent::compileDropAllTables($tables)];
    }

    /** SQL Server has no RESTRICT keyword (NO ACTION is the equivalent). */
    protected function foreignActions(ForeignKeyDefinition $fk): string
    {
        $map = static fn (string $action): string => $action === 'RESTRICT' ? 'NO ACTION' : $action;

        return ' ON DELETE ' . $map($fk->onDelete) . ' ON UPDATE ' . $map($fk->onUpdate);
    }

    protected function autoIncrementType(ColumnDefinition $column): string
    {
        return ($column->type === 'bigInteger' ? 'BIGINT' : 'INT') . ' IDENTITY(1,1) NOT NULL PRIMARY KEY';
    }

    public function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'string', 'enum' => 'NVARCHAR(' . (int) ($o['length'] ?? 255) . ')',
            'char' => 'NCHAR(' . (int) $o['length'] . ')',
            'text', 'mediumText', 'longText', 'json' => 'NVARCHAR(MAX)',
            'integer' => 'INT',
            'tinyInteger' => 'TINYINT',
            'boolean' => 'BIT',
            'float' => 'REAL',
            'double' => 'FLOAT',
            'dateTime', 'timestamp' => 'DATETIME2(0)',
            'time' => 'TIME(0)',
            default => parent::type($column),
        };
    }
}
