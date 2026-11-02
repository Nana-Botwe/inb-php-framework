<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database;
use Core\Database\Blueprint;
use Core\Database\Schema;
use Core\QueryBuilder;
use Tests\TestCase;

/**
 * Checks the SQL produced for every supported database. These tests need no database server:
 * grammars only generate SQL text.
 */
final class DatabaseDriversTest extends TestCase
{
    private const DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv', 'oci', 'firebird', 'odbc'];

    private function builder(string $driver, string $table = 'users'): QueryBuilder
    {
        return new QueryBuilder(new Database(['driver' => $driver]), $table);
    }

    public function test_every_driver_has_a_grammar_and_dsn(): void
    {
        $expected = [
            'mysql' => 'mysql:host=db;port=3306;dbname=app;charset=utf8mb4',
            'mariadb' => 'mysql:host=db;port=3306;dbname=app;charset=utf8mb4',
            'pgsql' => 'pgsql:host=db;port=5432;dbname=app',
            'sqlite' => 'sqlite:app',
            'oci' => 'oci:dbname=//db:1521/app;charset=AL32UTF8',
            'firebird' => 'firebird:dbname=db/3050:app;charset=UTF8',
            'odbc' => 'odbc:app',
        ];
        foreach ($expected as $driver => $dsn) {
            $this->assertSame($dsn, (new Database(['driver' => $driver, 'host' => 'db', 'database' => 'app']))->dsn(), "DSN for $driver");
        }
        $this->assertStringContainsString('Database=app', (new Database(['driver' => 'sqlsrv', 'host' => 'db', 'database' => 'app']))->dsn());
    }

    public function test_aliases_and_db_url(): void
    {
        $this->assertSame('pgsql', (new Database(['driver' => 'postgresql']))->driver());
        $this->assertSame('sqlsrv', (new Database(['driver' => 'mssql']))->driver());
        $this->assertSame('oci', (new Database(['driver' => 'oracle']))->driver());
        $this->assertSame('mysql', (new Database(['driver' => 'mariadb']))->driver());

        $db = new Database(['url' => 'postgres://app_user:s%40cret@db.example.com:6543/shop?sslmode=require']);
        $this->assertSame('pgsql', $db->driver());
        $this->assertSame('pgsql:host=db.example.com;port=6543;dbname=shop;sslmode=require', $db->dsn());
        $this->assertTrue($db->hasPassword());
        $this->assertFalse(isset($db->getConfig()['password']), 'getConfig() must never expose the password');
    }

    public function test_identifier_quoting_per_database(): void
    {
        $this->assertSame('`users`.`email`', Database::grammarFor('mysql')->wrap('users.email'));
        $this->assertSame('"users"."email"', Database::grammarFor('pgsql')->wrap('users.email'));
        $this->assertSame('[users].[email]', Database::grammarFor('sqlsrv')->wrap('users.email'));
        $this->assertSame('"users"."email"', Database::grammarFor('oci')->wrap('users.email'));

        foreach (self::DRIVERS as $driver) {
            $this->assertThrows(fn () => $this->builder($driver)->where('email; DROP TABLE users', 1)->toSql(), \InvalidArgumentException::class);
        }
    }

    public function test_limit_and_offset_syntax(): void
    {
        $sql = fn (string $d) => $this->builder($d)->orderBy('id')->limit(10)->offset(20)->toSql();

        $this->assertStringContainsString('LIMIT 10 OFFSET 20', $sql('mysql'));
        $this->assertStringContainsString('LIMIT 10 OFFSET 20', $sql('pgsql'));
        $this->assertStringContainsString('LIMIT 10 OFFSET 20', $sql('sqlite'));
        $this->assertStringContainsString('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', $sql('sqlsrv'));
        $this->assertStringContainsString('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', $sql('oci'));
        $this->assertStringContainsString('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', $sql('firebird'));

        // SQL Server needs an ORDER BY for OFFSET/FETCH.
        $this->assertStringContainsString('ORDER BY (SELECT 0) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY', $this->builder('sqlsrv')->limit(1)->toSql());
    }

    public function test_dialect_specific_functions(): void
    {
        $this->assertStringContainsString('RAND()', $this->builder('mysql')->inRandomOrder()->toSql());
        $this->assertStringContainsString('RANDOM()', $this->builder('pgsql')->inRandomOrder()->toSql());
        $this->assertStringContainsString('NEWID()', $this->builder('sqlsrv')->inRandomOrder()->toSql());
        $this->assertStringContainsString('DBMS_RANDOM.VALUE', $this->builder('oci')->inRandomOrder()->toSql());

        $this->assertStringContainsString('ILIKE ?', $this->builder('pgsql')->whereLike('name', 'ama')->toSql());
        $this->assertStringContainsString("LIKE ? ESCAPE '\\'", $this->builder('sqlsrv')->whereLike('name', 'ama')->toSql());
        $this->assertSame(['%50\\%%'], $this->builder('mysql')->whereLike('name', '50%')->getBindings());
    }

    public function test_insert_returning_ids(): void
    {
        $compile = fn (string $d) => Database::grammarFor($d)->compileInsertGetId('t', 'a', '?', 'id');

        $this->assertSame('INSERT INTO t (a) VALUES (?)', $compile('mysql'));
        $this->assertSame('INSERT INTO t (a) VALUES (?) RETURNING "id"', $compile('pgsql'));
        $this->assertSame('INSERT INTO t (a) OUTPUT INSERTED.[id] VALUES (?)', $compile('sqlsrv'));
        $this->assertSame('INSERT INTO t (a) VALUES (?) RETURNING "id" INTO :__insert_id', $compile('oci'));
    }

    public function test_schema_for_every_database(): void
    {
        $define = static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->boolean('published')->default(false);
            $table->enum('status', ['draft', 'live'])->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
        };

        $mysql = implode("\n", Schema::toSql('posts', $define, 'mysql'));
        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $mysql);
        $this->assertStringContainsString("ENUM('draft', 'live') DEFAULT 'draft' NOT NULL", $mysql);
        $this->assertStringContainsString('ENGINE=InnoDB', $mysql);

        $pgsql = implode("\n", Schema::toSql('posts', $define, 'pgsql'));
        $this->assertStringContainsString('"id" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', $pgsql);
        $this->assertStringContainsString('"published" BOOLEAN DEFAULT FALSE NOT NULL', $pgsql);
        $this->assertStringContainsString('JSONB', $pgsql);

        $sqlsrv = implode("\n", Schema::toSql('posts', $define, 'sqlsrv'));
        $this->assertStringContainsString('[id] BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY', $sqlsrv);
        $this->assertStringContainsString('[published] BIT DEFAULT 0 NOT NULL', $sqlsrv);
        $this->assertStringContainsString('NVARCHAR(MAX)', $sqlsrv);

        $oci = implode("\n", Schema::toSql('posts', $define, 'oci'));
        $this->assertStringContainsString('"id" NUMBER(19) GENERATED BY DEFAULT ON NULL AS IDENTITY PRIMARY KEY', $oci);
        $this->assertStringContainsString('VARCHAR2(200 CHAR)', $oci);
        $this->assertStringContainsString('ON DELETE CASCADE', $oci);
        $this->assertFalse(str_contains($oci, 'ON UPDATE'), 'Oracle has no ON UPDATE actions');

        $sqlite = implode("\n", Schema::toSql('posts', $define, 'sqlite'));
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite);
        $this->assertStringContainsString("CHECK (\"status\" IN ('draft', 'live'))", $sqlite);

        foreach (['firebird', 'odbc'] as $driver) {
            $this->assertStringContainsString('CREATE TABLE "posts"', implode("\n", Schema::toSql('posts', $define, $driver)));
        }
    }

    public function test_the_real_test_database_runs_the_full_migrations(): void
    {
        // The suite itself runs on SQLite; MySQL/MariaDB is exercised by "php console migrate".
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('payments', 'amount_minor'));
        $id = \Core\DB::table('users')->insertGetId(['name' => 'X', 'email' => 'x@example.com', 'password' => 'h', 'role' => 'user']);
        $this->assertTrue(ctype_digit($id) && (int) $id > 0, 'insertGetId returns the new numeric id');
    }
}
