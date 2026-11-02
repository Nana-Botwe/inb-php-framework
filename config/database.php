<?php

/*
|--------------------------------------------------------------------------
| Database connections
|--------------------------------------------------------------------------
| Supported: mysql, mariadb, pgsql (PostgreSQL), sqlite, sqlsrv (SQL Server / Azure SQL),
| oracle (oci), firebird, odbc — plus any PDO DSN via "dsn" or a URL via DB_URL.
|
| Pick the default with DB_CONNECTION (or the older DB_DRIVER). Several connections can be
| used at once:  DB::connection('reporting')->table('sales')->get();
| A model can use another connection:  protected static ?string $connection = 'reporting';
*/

$sqlitePath = static function (string $path): string {
    if ($path === '' || $path === ':memory:' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
        return $path === '' ? base_path('database/database.sqlite') : $path;
    }

    return base_path($path);
};

$default = (string) env('DB_CONNECTION', env('DB_DRIVER', 'mysql'));

return [
    'default' => $default,

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL', ''),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'ssl_ca' => env('MYSQL_ATTR_SSL_CA', ''),
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL', ''),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL', ''),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'schema' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),          // disable | prefer | require | verify-full
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath((string) env('DB_DATABASE', 'database/database.sqlite')),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL', ''),
            'host' => env('DB_HOST', 'localhost'),
            'port' => (int) env('DB_PORT', 1433),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'encrypt' => (bool) env('DB_ENCRYPT', true),
            'trust_server_certificate' => (bool) env('DB_TRUST_SERVER_CERTIFICATE', false),
        ],

        'oracle' => [
            'driver' => 'oci',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 1521),
            'service_name' => env('DB_SERVICE_NAME', env('DB_DATABASE', 'XEPDB1')),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'AL32UTF8',
        ],

        'firebird' => [
            'driver' => 'firebird',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3050),
            'database' => env('DB_DATABASE', ''),            // path or alias of the .fdb file
            'username' => env('DB_USERNAME', 'SYSDBA'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'UTF8',
        ],

        // Any database with an ODBC driver (IBM Db2, Access, Informix, Snowflake ...).
        'odbc' => [
            'driver' => 'odbc',
            'database' => env('DB_ODBC_DSN', ''),            // e.g. "Driver={IBM DB2 ODBC DRIVER};Database=SAMPLE;Hostname=...;Port=50000"
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
        ],

        // A SEPARATE, empty database that "php console deploy:verify --restore-test=restore_test" overwrites with
        // the newest backup to prove it restores. Its name must contain "restore" or "test".
        // DB_RESTORE_TEST_URL=mysql://app_user:secret@127.0.0.1:3306/my_shop_restore_test
        'restore_test' => [
            'driver' => $default,
            'url' => env('DB_RESTORE_TEST_URL', ''),
        ],

        // A raw PDO DSN for anything else:
        // 'custom' => ['driver' => 'pgsql', 'dsn' => 'pgsql:host=db;dbname=app', 'username' => '...', 'password' => '...'],
    ],
];
