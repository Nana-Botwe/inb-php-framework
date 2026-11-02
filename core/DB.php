<?php

declare(strict_types=1);

namespace Core;

/**
 * Database facade (default connection, or any named one):
 *   DB::table('orders')->where('status', 'paid')->sum('total');
 *   DB::select('SELECT * FROM users WHERE id = ?', [$id]);
 *   DB::transaction(function () { ... });
 *   DB::connection('reporting')->table('sales')->get();      // a second database
 */
final class DB
{
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(Database::instance(), $table);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return Database::instance()->select($sql, $bindings);
    }

    /** @param array<int|string, mixed> $bindings */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return Database::instance()->selectOne($sql, $bindings);
    }

    /** @param array<int|string, mixed> $bindings */
    public static function statement(string $sql, array $bindings = []): int
    {
        return Database::instance()->execute($sql, $bindings);
    }

    public static function transaction(callable $callback): mixed
    {
        return Database::instance()->transaction($callback);
    }

    /** A named connection from config/database.php, or the default one. */
    public static function connection(?string $name = null): Database
    {
        return $name === null ? Database::instance() : Database::connection($name);
    }

    /** The default connection's driver family: mysql, pgsql, sqlite, sqlsrv, oci, firebird, odbc. */
    public static function driver(): string
    {
        return Database::instance()->driver();
    }
}
