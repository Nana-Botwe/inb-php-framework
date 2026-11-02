<?php

declare(strict_types=1);

namespace Core\Database;

use Closure;
use Core\Database;

/**
 * Schema builder for migrations — works on MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle and Firebird.
 *
 *   Schema::create('posts', fn (Blueprint $table) => ...);
 *   Schema::table('posts', fn (Blueprint $table) => $table->string('slug')->nullable());
 *   Schema::dropIfExists('posts');
 *   Schema::connection('reporting')->create('daily_sales', ...);   // another database
 *
 * @method static void create(string $table, Closure $callback)
 * @method static void table(string $table, Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 * @method static string[] getTables()
 * @method static string[] getColumns(string $table)
 * @method static void dropAllTables()
 * @method static void withoutForeignKeyConstraints(Closure $callback)
 */
final class Schema
{
    public static function connection(?string $name = null): SchemaBuilder
    {
        return new SchemaBuilder($name === null ? Database::instance() : Database::connection($name));
    }

    /** SQL that create() would run — handy to preview a migration on another database. @return string[] */
    public static function toSql(string $table, Closure $callback, ?string $driver = null): array
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $grammar = $driver === null ? Database::instance()->grammar() : Database::grammarFor($driver);

        return $grammar->compileCreate($blueprint);
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::connection()->{$method}(...$arguments);
    }
}
