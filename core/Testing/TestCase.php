<?php

declare(strict_types=1);

namespace Core\Testing;

use Core\App;
use Core\Auth;
use Core\Config;
use Core\Csrf;
use Core\Database;
use Core\Database\Migrator;
use Core\DB;
use Core\Mail\Mail;
use Core\Model;
use Core\Payments\Payment;
use Core\Request;
use Core\Response;
use Core\Sms\Sms;
use Throwable;

/**
 * Base class for tests in tests/ (php console test). Each test gets a fresh in-memory SQLite
 * database with all migrations, the "array" mailer and the "log" SMS gateway.
 *
 *   public function test_home_page(): void { $this->get('/')->assertOk()->assertSee('Welcome'); }
 */
abstract class TestCase
{
    protected App $app;

    private int $assertions = 0;

    /** @var array<string, string> */
    private array $defaultHeaders = ['HTTP_USER_AGENT' => 'INB-Test'];

    /** Real-database mode (TEST_DB_URL): migrations run once per process, each test is rolled back. */
    private static bool $realDatabaseMigrated = false;

    private bool $usesRealDatabase = false;

    /**
     * Each test gets a clean database:
     *  - default: a fresh in-memory SQLite database
     *  - TEST_DB_URL=mysql://user:pass@host/inb_test (or pgsql://, sqlsrv:// ...): the real engine.
     *    The database NAME must contain "test" (protects real data); it is wiped once, migrated, and every
     *    test runs inside a transaction that is rolled back.
     */
    public function setUpTest(App $app): void
    {
        $this->app = $app;

        $testUrl = (string) (getenv('TEST_DB_URL') ?: '');
        Database::purge();

        if ($testUrl !== '') {
            $this->usesRealDatabase = true;
            $database = new Database(['url' => $testUrl], 'testing');
            $name = (string) ($database->getConfig()['database'] ?? '');
            if (!str_contains(strtolower($name), 'test')) {
                throw new \RuntimeException("Refusing to run tests on database \"$name\": its name must contain \"test\".");
            }
            Config::set('database.default', 'testing');
            Config::set('database.connections.testing', $database->getConfig());
            Database::setInstance($database);
        } else {
            Config::set('database.default', 'testing');
            Config::set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
            Database::setInstance(new Database((array) Config::get('database.connections.testing'), 'testing'));
        }

        Config::set('mail.default', 'array');
        Config::set('sms.default', 'log');
        Config::set('queue.default', 'sync');
        Config::set('cache.store', 'file');
        Config::set('security.rate_limiter.store', 'file');
        Config::set('app.debug', false);
        Config::set('app.env', 'testing');
        Config::set('app.url', 'http://localhost');
        Config::set('app.trusted_hosts', []);
        Config::set('auth.passwords.response_floor_ms', 0);
        // Low bcrypt cost keeps tests fast (production uses config/hashing.php).
        Config::set('hashing.bcrypt_rounds', 4);
        \Core\ErrorHandler::register(false, storage_path('logs'));
        \Core\Auth\Credentials::flushColumnCache();
        \Core\Auth\Gate::flush();
        Mail::$sent = [];
        Payment::flush();
        Sms::flush();

        $migrator = new Migrator($app->basePath . '/database/migrations');
        if ($this->usesRealDatabase) {
            if (!self::$realDatabaseMigrated) {
                \Core\Database\Schema::dropAllTables();
                $migrator->run();
                self::$realDatabaseMigrated = true;
            }
            Database::instance()->pdo()->beginTransaction();
        } else {
            $migrator->run();
        }

        $_SESSION = [];
        Auth::flush();
        foreach (glob(storage_path('cache/ratelimit') . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(storage_path('framework/cache') . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        $this->setUp();
    }

    /** Always called by the runner, even when the test failed. */
    public function tearDownTest(): void
    {
        try {
            $this->tearDown();
        } finally {
            if ($this->usesRealDatabase && Database::instance()->pdo()->inTransaction()) {
                Database::instance()->pdo()->rollBack();
            }
            \Core\Http\Client::stopFaking();
        }
    }

    public function usesRealDatabase(): bool
    {
        return $this->usesRealDatabase;
    }

    protected function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    // ------------------------------------------------------------------ HTTP

    /** @param array<string, string> $headers e.g. ['Accept' => 'application/json'] */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers);
    }

    /** @param array<string, mixed> $data */
    public function post(string $uri, array $data = [], array $headers = [], array $files = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers, $files);
    }

    /** @param array<string, mixed> $data */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data + ['_method' => 'PUT'], $headers);
    }

    /** @param array<string, mixed> $data */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data + ['_method' => 'DELETE'], $headers);
    }

    /** @param array<string, mixed> $data */
    public function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers + ['Accept' => 'application/json']);
    }

    /** @param array<string, mixed> $data */
    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers + ['Accept' => 'application/json']);
    }

    /**
     * Send a request through the full application (firewall, middleware, routing, views).
     * A valid CSRF token is added automatically unless you pass your own "_token".
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @param array<string, array<string, mixed>> $files
     */
    public function call(string $method, string $uri, array $data = [], array $headers = [], array $files = []): TestResponse
    {
        if ($method !== 'GET' && !array_key_exists('_token', $data) && !isset($headers['X-CSRF-TOKEN'])) {
            $data['_token'] = Csrf::token();
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $server = $this->defaultHeaders + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'QUERY_STRING' => (string) parse_url($uri, PHP_URL_QUERY),
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        Auth::flush();
        $request = new Request($method === 'GET' ? $query : $query, $method === 'GET' ? [] : $data, $server, $files, $this->cookies);
        $response = $this->app->handle($request);
        $this->app->terminate();   // after-response work (queued emails in "sync" mode), like a real request

        foreach ($response->getCookies() as $name => $cookie) {
            if ($cookie['expires'] !== 0 && $cookie['expires'] < time()) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $cookie['value'];
            }
        }
        Response::flushQueuedCookies(); // send() does this for real requests

        return new TestResponse($response, $this);
    }

    /** @var array<string, string> */
    private array $cookies = [];

    /** The test browser's current value of a cookie. */
    public function cookieValue(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** Set (or with null, remove) a cookie in the test browser. */
    public function setCookie(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    public function actingAs(Model $user): static
    {
        Auth::login($user);
        // Bind the session to the test "browser", exactly like a real login request would.
        \Core\Session::set('_auth_ua', hash('sha256', (string) ($this->defaultHeaders['HTTP_USER_AGENT'] ?? '')));

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->defaultHeaders['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this;
    }

    /** A real temporary file to upload: $this->post('/avatar', [], [], ['avatar' => $this->fakeUpload('a.png', $png)]) */
    protected function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inb');
        file_put_contents((string) $tmp, $contents);

        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents), 'type' => 'application/octet-stream', 'test' => true];
    }

    protected function fakeImage(string $name = 'photo.jpg', int $width = 800, int $height = 600): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 30, 120, 220));
        ob_start();
        imagejpeg($image, null, 95);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $this->fakeUpload($name, $data);
    }

    // ------------------------------------------------------------------ assertions

    public function assertTrue(mixed $value, string $message = 'Expected true.'): void
    {
        $this->assert($value === true, $message);
    }

    public function assertFalse(mixed $value, string $message = 'Expected false.'): void
    {
        $this->assert($value === false, $message);
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assert($expected === $actual, $message ?: 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual) . '.');
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assert($expected == $actual, $message ?: 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual) . '.');
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $message = 'Values should differ.'): void
    {
        $this->assert($expected !== $actual, $message);
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assert($value === null, $message ?: 'Expected null, got ' . $this->export($value) . '.');
    }

    public function assertNotNull(mixed $value, string $message = 'Expected a value, got null.'): void
    {
        $this->assert($value !== null, $message);
    }

    public function assertCount(int $expected, \Countable|array $items, string $message = ''): void
    {
        $count = count($items);
        $this->assert($count === $expected, $message ?: "Expected $expected items, got $count.");
    }

    public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        $found = false;
        foreach ($haystack as $item) {
            $found = $found || $item === $needle;
        }
        $this->assert($found, $message ?: 'Expected to contain ' . $this->export($needle) . '.');
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assert(str_contains($haystack, $needle), $message ?: "Expected the text to contain \"$needle\".");
    }

    public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assert(!str_contains($haystack, $needle), $message ?: "Expected the text NOT to contain \"$needle\".");
    }

    public function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        $this->assert($value instanceof $class, $message ?: "Expected an instance of $class.");
    }

    /** @param class-string<Throwable> $exception */
    public function assertThrows(callable $callback, string $exception = Throwable::class, string $message = ''): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->assert($e instanceof $exception, $message ?: "Expected $exception, got " . $e::class . ': ' . $e->getMessage());

            return;
        }
        $this->assert(false, $message ?: "Expected $exception to be thrown.");
    }

    /** @param array<string, mixed> $data */
    public function assertDatabaseHas(string $table, array $data): void
    {
        $this->assert(DB::table($table)->where($data)->exists(), "No row in [$table] matches " . $this->export($data) . '.');
    }

    /** @param array<string, mixed> $data */
    public function assertDatabaseMissing(string $table, array $data): void
    {
        $this->assert(!DB::table($table)->where($data)->exists(), "A row in [$table] matches " . $this->export($data) . '.');
    }

    public function assertDatabaseCount(string $table, int $count): void
    {
        $actual = DB::table($table)->count();
        $this->assert($actual === $count, "Expected $count rows in [$table], found $actual.");
    }

    public function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    private function export(mixed $value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
