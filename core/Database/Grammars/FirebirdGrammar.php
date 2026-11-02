<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

use Core\Database\ColumnDefinition;

/** Firebird 3+ (standard SQL with a few type differences). */
class FirebirdGrammar extends Grammar
{
    public function name(): string
    {
        return 'firebird';
    }

    public function random(): string
    {
        return 'RAND()';
    }

    public function insertIdStrategy(): string
    {
        return 'returning';
    }

    public function compileTables(): array
    {
        return ['SELECT TRIM(rdb$relation_name) AS name FROM rdb$relations WHERE rdb$system_flag = 0 AND rdb$view_blr IS NULL', [], 'name'];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT TRIM(rdb$field_name) AS name FROM rdb$relation_fields WHERE rdb$relation_name = ? ORDER BY rdb$field_position', [$table], 'name'];
    }

    /** Firebird has no IF EXISTS for tables before version 4.0. */
    public function compileDropIfExists(string $table): string
    {
        return 'EXECUTE BLOCK AS BEGIN IF (EXISTS(SELECT 1 FROM rdb$relations WHERE rdb$relation_name = ' . $this->quoteString($table)
            . ")) THEN EXECUTE STATEMENT 'DROP TABLE " . str_replace("'", "''", $this->wrap($table)) . "'; END";
    }

    public function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'text', 'mediumText', 'longText', 'json' => 'BLOB SUB_TYPE TEXT',
            'tinyInteger' => 'SMALLINT',
            default => parent::type($column),
        };
    }
}
