<?php

declare(strict_types=1);

namespace Core\Database\Grammars;

/**
 * Generic ODBC connection (IBM Db2, Access, Informix, Snowflake, ...): standard SQL:2008.
 * Queries work through the standard grammar; use DB::statement() for database-specific DDL.
 */
class OdbcGrammar extends Grammar
{
    public function name(): string
    {
        return 'odbc';
    }
}
