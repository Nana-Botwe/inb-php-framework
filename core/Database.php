<?php

declare(strict_types=1);

namespace Core;

use Core\Database\Grammars\FirebirdGrammar;
use Core\Database\Grammars\Grammar;
use Core\Database\Grammars\MySqlGrammar;
use Core\Database\Grammars\OdbcGrammar;
use Core\Database\Grammars\OracleGrammar;
use Core\Database\Grammars\PostgresGrammar;
use Core\Database\Grammars\SQLiteGrammar;
use Core\Database\Grammars\SqlServerGrammar;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * A database connection. Supports every PDO database:
 *   mysql, mariadb, pgsql (PostgreSQL), sqlite, sqlsrv (SQL Server / Azure SQL), oci (Oracle), firebird, odbc
 *
 * Several connections can be open at once (config/database.php → "connections"):
 *   Database::connection('reporting')->select('SELECT ...');   DB::connection('legacy')->table('orders')->get();
 *
 * Connections open lazily, use real prepared statements and throw exceptions on errors.
 */
final class Database
{
    /** Driver aliases people commonly use. */
    private const ALIASES = [
        'mariadb' => 'mysql', 'mysqli' => 'mysql',
        'postgres' => 'pgsql', 'postgresql' => 'pgsql', 'pgsql' => 'pgsql',
        'sqlite3' => 'sqlite',
        'mssql' => 'sqlsrv', 'sqlserver' => 'sqlsrv', 'dblib' => 'sqlsrv',
        'oracle' => 'oci', 'oci8' => 'oci',
        'interbase' => 'firebird',
    ];

    /** @var array<string, self> */
    private static array $connections = [];

    private static ?self $defaultOverride = null;

    private ?PDO $pdo = null;

    private ?Grammar $grammar = null;

    /** @var array<string, mixed> */
    private readonly array $config;

    /** @param array<string, mixed> $config One entry of database.connections (or a full DB_URL in "url"). */
    public function __construct(array $config, private readonly string $name = 'default')
    {
        $this->config = self::parseUrl($config);
    }

    // ------------------------------------------------------------------ connection registry

    /** The default connection. */
    public static function instance(): self
    {
        return self::$defaultOverride ?? self::connection();
    }

    /** A named connection from config/database.php (null = the default one). */
    public static function connection(?string $name = null): self
    {
        if ($name === null && self::$defaultOverride !== null) {
            return self::$defaultOverride;
        }

        $name ??= (string) Config::get('database.default', 'mysql');

        if (!isset(self::$connections[$name])) {
            $config = Config::get("database.connections.$name");
            // Accept driver names too: DB_CONNECTION=postgres -> "pgsql", oci -> "oracle", mssql -> "sqlsrv".
            if (!is_array($config)) {
                $family = self::ALIASES[strtolower($name)] ?? strtolower($name);
                $config = Config::get('database.connections.' . ($family === 'oci' ? 'oracle' : $family));
            }
            if (!is_array($config)) {
                throw new InvalidArgumentException("Database connection [$name] is not configured in config/database.php.");
            }
            self::$connections[$name] = new self($config, $name);
        }

        return self::$connections[$name];
    }

    /** Replace the default connection (tests) — pass null to restore it. */
    public static function setInstance(?self $database): void
    {
        self::$defaultOverride = $database;
    }

    public static function purge(?string $name = null): void
    {
        if ($name === null) {
            self::$connections = [];
        } else {
            unset(self::$connections[$name]);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> Config without the password. */
    public function getConfig(): array
    {
        return array_diff_key($this->config, ['password' => true]);
    }

    public function hasPassword(): bool
    {
        return (string) ($this->config['password'] ?? '') !== '';
    }

    /**
     * The resolved password (also when it came from a DB_URL), for passing to mysqldump / psql through an
     * environment variable. Never log it or put it on a command line.
     */
    public function passwordForExternalTool(): string
    {
        return (string) ($this->config['password'] ?? '');
    }

    // ------------------------------------------------------------------ driver & grammar

    /** Normalised driver family: mysql | pgsql | sqlite | sqlsrv | oci | firebird | odbc */
    public function driver(): string
    {
        $driver = strtolower((string) ($this->config['driver'] ?? 'mysql'));

        return self::ALIASES[$driver] ?? $driver;
    }

    public function grammar(): Grammar
    {
        return $this->grammar ??= self::grammarFor($this->driver());
    }

    public static function grammarFor(string $driver): Grammar
    {
        $driver = self::ALIASES[strtolower($driver)] ?? strtolower($driver);

        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SQLiteGrammar(),
            'sqlsrv' => new SqlServerGrammar(),
            'oci' => new OracleGrammar(),
            'firebird' => new FirebirdGrammar(),
            'odbc' => new OdbcGrammar(),
            default => throw new InvalidArgumentException("Unsupported database driver [$driver]. Use mysql, mariadb, pgsql, sqlite, sqlsrv, oci, firebird or odbc."),
        };
    }

    /** PDO extension needed for this connection. */
    public function pdoDriver(): string
    {
        $raw = strtolower((string) ($this->config['driver'] ?? 'mysql'));

        $available = PDO::getAvailableDrivers();

        return match ($this->driver()) {
            // Microsoft's pdo_sqlsrv by default; FreeTDS (pdo_dblib, common on Linux) when asked for or when it's the only one installed.
            'sqlsrv' => $raw === 'dblib' || (!in_array('sqlsrv', $available, true) && in_array('dblib', $available, true)) ? 'dblib' : 'sqlsrv',
            default => $this->driver(),
        };
    }

    /** Build the PDO DSN for any supported database (or use "dsn" from the config as-is). */
    public function dsn(): string
    {
        $c = $this->config;
        if (!empty($c['dsn'])) {
            return (string) $c['dsn'];
        }

        $host = (string) ($c['host'] ?? '127.0.0.1');
        $database = (string) ($c['database'] ?? '');

        return match ($this->driver()) {
            'mysql' => isset($c['unix_socket']) && $c['unix_socket'] !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['unix_socket'], $database, $c['charset'] ?? 'utf8mb4')
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int) ($c['port'] ?? 3306), $database, $c['charset'] ?? 'utf8mb4'),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, (int) ($c['port'] ?? 5432), $database)
                . (!empty($c['sslmode']) ? ';sslmode=' . $c['sslmode'] : '')
                . (!empty($c['application_name']) ? ';application_name=' . $c['application_name'] : ''),
            'sqlite' => 'sqlite:' . ($database !== '' ? $database : ':memory:'),
            'sqlsrv' => $this->pdoDriver() === 'dblib'
                ? sprintf('dblib:host=%s:%d;dbname=%s;charset=UTF-8', $host, (int) ($c['port'] ?? 1433), $database)
                : sprintf('sqlsrv:Server=%s,%d;Database=%s', $host, (int) ($c['port'] ?? 1433), $database)
                    . (isset($c['encrypt']) ? ';Encrypt=' . ($c['encrypt'] ? 'yes' : 'no') : '')
                    . (isset($c['trust_server_certificate']) ? ';TrustServerCertificate=' . ($c['trust_server_certificate'] ? 'yes' : 'no') : ''),
            'oci' => 'oci:dbname=' . (!empty($c['service_name'])
                    ? sprintf('//%s:%d/%s', $host, (int) ($c['port'] ?? 1521), $c['service_name'])
                    : ($database !== '' && str_contains($database, '/') ? $database : sprintf('//%s:%d/%s', $host, (int) ($c['port'] ?? 1521), $database)))
                . ';charset=' . ($c['charset'] ?? 'AL32UTF8'),
            'firebird' => sprintf('firebird:dbname=%s/%d:%s;charset=%s', $host, (int) ($c['port'] ?? 3050), $database, $c['charset'] ?? 'UTF8'),
            'odbc' => 'odbc:' . $database,
        };
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = $this->driver();
        $pdoDriver = $this->pdoDriver();
        if (!in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
            throw new Exceptions\DatabaseException(
                "The PHP extension for [$pdoDriver] is not enabled (available: " . implode(', ', PDO::getAvailableDrivers()) . ').',
            );
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
        ];

        // Real prepared statements: the SQL and the values travel separately.
        if (in_array($driver, ['mysql', 'pgsql', 'sqlite', 'firebird'], true)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        if ($driver === 'mysql') {
            // One statement per query: a stacked "; DROP TABLE ..." can never run.
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            if (!empty($this->config['ssl_ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = (string) $this->config['ssl_ca'];
            }
        }

        if ($driver === 'oci') {
            $options[PDO::ATTR_CASE] = PDO::CASE_LOWER; // Oracle returns unquoted aliases in UPPER case
        }

        foreach ((array) ($this->config['options'] ?? []) as $key => $value) {
            $options[$key] = $value;
        }

        try {
            $this->pdo = new PDO($this->dsn(), $this->config['username'] ?? null, $this->config['password'] ?? null, $options);
        } catch (\PDOException $e) {
            // Re-thrown without the PDO constructor in the trace (it would contain the password).
            throw new Exceptions\DatabaseException(
                'Could not connect to the ' . $driver . ' database "' . ($this->config['database'] ?? '') . '": ' . $e->getMessage(),
                (int) $e->getCode(),
            );
        }

        foreach ($this->grammar()->sessionStatements() as $statement) {
            $this->pdo->exec($statement);
        }
        if ($driver === 'pgsql' && !empty($this->config['schema'])) {
            $this->pdo->exec('SET search_path TO ' . $this->grammar()->wrap((string) $this->config['schema']));
        }

        return $this->pdo;
    }

    // ------------------------------------------------------------------ querying

    /** Start a query on a table of this connection. */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return array_map([$this, 'normaliseRow'], $this->query($sql, $params)->fetchAll());
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $this->normaliseRow($row);
    }

    /**
     * Run INSERT/UPDATE/DELETE and return the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Insert a row and return its new ID, using the right technique for each database
     * (lastInsertId, RETURNING, OUTPUT INSERTED, RETURNING ... INTO).
     *
     * @param array<int, mixed> $bindings
     */
    public function insertGetId(string $sql, array $bindings): string
    {
        return match ($this->grammar()->insertIdStrategy()) {
            'returning', 'output' => (string) $this->query($sql, $bindings)->fetchColumn(),
            'returning_into' => $this->insertReturningInto($sql, $bindings),
            default => (function () use ($sql, $bindings): string {
                $this->execute($sql, $bindings);

                return (string) $this->pdo()->lastInsertId();
            })(),
        };
    }

    /** @param array<int, mixed> $bindings */
    private function insertReturningInto(string $sql, array $bindings): string
    {
        // Positional "?" and a named out-parameter can't be mixed, so convert to :p1, :p2 ...
        $index = 0;
        $named = (string) preg_replace_callback('/\?/', static function () use (&$index): string {
            return ':p' . (++$index);
        }, $sql);

        $statement = $this->pdo()->prepare($named);
        foreach (array_values($bindings) as $i => $value) {
            $statement->bindValue(':p' . ($i + 1), $value);
        }
        $id = null;
        $statement->bindParam(':__insert_id', $id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $statement->execute();

        return (string) $id;
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction; rolls back automatically if it throws.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        // Nested call: join the outer transaction (it commits or rolls back everything).
        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * True only when $e (or an exception it wraps) is a UNIQUE / PRIMARY KEY violation — not any other
     * constraint, connection or SQL error. Use it to tell "this row already exists" apart from real failures.
     */
    public static function isUniqueViolation(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \PDOException) {
                continue;
            }
            $sqlState = (string) ($current->errorInfo[0] ?? $current->getCode());
            $code = (int) ($current->errorInfo[1] ?? 0);
            $message = $current->getMessage();

            if ($sqlState === '23505') {                                   // PostgreSQL (and the SQL standard)
                return true;
            }
            if (!str_starts_with($sqlState, '23') && !str_starts_with($sqlState, 'HY')) {
                continue;
            }
            if (in_array($code, [1062, 1586, 2627, 2601], true)) {          // MySQL/MariaDB, SQL Server
                return true;
            }
            if (preg_match('/UNIQUE constraint failed|PRIMARY KEY must be unique|ORA-00001|violation of PRIMARY or UNIQUE KEY|Duplicate entry/i', $message) === 1) {
                return true;                                               // SQLite, Oracle, Firebird, ODBC drivers
            }
        }

        return false;
    }

    /** Safely quote a table/column name for this database. */
    public function quoteIdentifier(string $name): string
    {
        return $this->grammar()->wrap($name);
    }

    /**
     * LOB columns (Oracle CLOB, PostgreSQL bytea, Firebird BLOB) may come back as streams.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                $row[$key] = (string) stream_get_contents($value);
            }
        }

        return $row;
    }

    /**
     * Support DB_URL style config: mysql://user:pass@host:3306/dbname?charset=utf8mb4
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function parseUrl(array $config): array
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            return $config;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new InvalidArgumentException('DB_URL is not a valid database URL.');
        }

        $fromUrl = array_filter([
            'driver' => $parts['scheme'],
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'database' => isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : null,
        ], static fn ($v) => $v !== null && $v !== '');

        parse_str($parts['query'] ?? '', $query);

        return array_merge($config, $fromUrl, $query);
    }
}
