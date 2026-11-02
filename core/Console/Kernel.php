<?php

declare(strict_types=1);

namespace Core\Console;

use Closure;
use Core\App;
use Core\Cache;
use Core\Config;
use Core\Crypt;
use Core\Database;
use Core\Database\Migrator;
use Core\Database\Schema;
use Core\DB;
use Core\Mail\Mail;
use Core\Queue\Queue;
use Core\Sms\Sms;
use Core\Support\Str;
use Throwable;

/**
 * "php console <command>" — the framework's command-line tool (like Laravel's Artisan).
 */
final class Kernel
{
    /** @var array<string, array{description: string, handler: Closure}> */
    private array $commands = [];

    /** @var array<string, Command> */
    private array $custom = [];

    private Schedule $schedule;

    /** Credentials each installable gateway needs: name => [ENV_KEY => [default, comment]] */
    private const PAYMENT_ENV = [
        'paystack' => ['PAYSTACK_PUBLIC_KEY' => '', 'PAYSTACK_SECRET_KEY' => ''],
        'flutterwave' => ['FLUTTERWAVE_PUBLIC_KEY' => '', 'FLUTTERWAVE_SECRET_KEY' => '', 'FLUTTERWAVE_WEBHOOK_HASH' => ''],
        'stripe' => ['STRIPE_PUBLIC_KEY' => '', 'STRIPE_SECRET_KEY' => '', 'STRIPE_WEBHOOK_SECRET' => ''],
        'paypal' => ['PAYPAL_MODE' => 'sandbox', 'PAYPAL_CLIENT_ID' => '', 'PAYPAL_CLIENT_SECRET' => '', 'PAYPAL_WEBHOOK_ID' => ''],
        'hubtel' => ['HUBTEL_CLIENT_ID' => '', 'HUBTEL_CLIENT_SECRET' => '', 'HUBTEL_MERCHANT_ACCOUNT' => ''],
    ];

    private const SMS_ENV = [
        'twilio' => ['TWILIO_SID' => '', 'TWILIO_TOKEN' => '', 'TWILIO_FROM' => ''],
        'africastalking' => ['AFRICASTALKING_USERNAME' => 'sandbox', 'AFRICASTALKING_API_KEY' => '', 'AFRICASTALKING_FROM' => ''],
        'hubtel' => ['HUBTEL_SMS_CLIENT_ID' => '', 'HUBTEL_SMS_CLIENT_SECRET' => '', 'HUBTEL_SMS_FROM' => ''],
        'arkesel' => ['ARKESEL_API_KEY' => '', 'ARKESEL_FROM' => ''],
    ];

    private const MAIL_THEMES = ['modern', 'classic', 'minimal'];

    public function __construct(private readonly App $app)
    {
        $this->schedule = new Schedule();
        $this->registerBuiltIn();
        $this->discoverCustomCommands();

        $console = $this;
        $schedule = $this->schedule;
        if (is_file($app->basePath . '/routes/console.php')) {
            require $app->basePath . '/routes/console.php';
        }
    }

    /** Define a closure command in routes/console.php: $console->command('hello {name}', fn ($name) => ...) */
    public function command(string $signature, Closure $handler, string $description = ''): void
    {
        $name = (string) strtok($signature, ' ');
        $this->commands[$name] = ['description' => $description, 'handler' => static function (array $args) use ($handler): int {
            [$positional] = Input::parse($args);

            return (int) ($handler(...$positional) ?? 0);
        }];
    }

    /** @param string[] $argv */
    public function handle(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        try {
            if (isset($this->custom[$name])) {
                $command = $this->custom[$name];
                $command->setInput($args);

                return $command->handle();
            }

            if (!isset($this->commands[$name])) {
                Output::error("Command \"$name\" is not defined.");
                $suggestion = $this->suggest($name);
                if ($suggestion !== null) {
                    Output::line("Did you mean: php console $suggestion");
                }

                return 1;
            }

            return (int) ($this->commands[$name]['handler']($args) ?? 0);
        } catch (Throwable $e) {
            \Core\ErrorHandler::report($e);
            \Core\ErrorHandler::renderConsole($e);

            return 1;
        }
    }

    // ================================================================== built-in commands

    private function registerBuiltIn(): void
    {
        $this->add('list', 'List all commands', fn () => $this->listCommands());
        $this->add('about', 'Show framework, PHP and environment details', fn () => $this->about());
        $this->add('serve', 'Start the development server [port=8000]', fn (array $a) => $this->serve($a));
        $this->add('key:generate', 'Set a new APP_KEY in .env (encryption, signed URLs, tokens)', fn (array $a) => $this->keyGenerate($a));
        $this->add('route:list', 'List all routes', fn () => $this->routeList());
        $this->add('security:check', 'Audit the configuration for security problems', fn () => $this->securityCheck());

        // database
        $this->add('migrate', 'Run pending migrations', fn (array $a) => $this->migrate('run', $a));
        $this->add('migrate:rollback', 'Undo the last batch of migrations [--step=N]', fn (array $a) => $this->migrate('rollback', $a));
        $this->add('migrate:reset', 'Undo all migrations', fn (array $a) => $this->migrate('reset', $a));
        $this->add('migrate:fresh', 'Drop all tables and migrate again [--seed]', fn (array $a) => $this->migrate('fresh', $a));
        $this->add('migrate:status', 'Show which migrations have run', fn () => $this->migrateStatus());
        $this->add('db:seed', 'Run database seeders [--class=UserSeeder]', fn (array $a) => $this->seed($a));
        $this->add('db:show', 'Show the database connection and tables', fn () => $this->dbShow());
        $this->add('db:drivers', 'Show supported databases and which PHP drivers are installed', fn () => $this->dbDrivers());
        $this->add('db:test', 'Test a connection: db:test [connection]', fn (array $a) => $this->dbTest($a[0] ?? null));

        // generators
        $this->add('make:controller', 'Create a controller [--resource] [--api]', fn (array $a) => $this->makeController($a));
        $this->add('make:model', 'Create a model [-m migration] [-f factory] [-s seeder]', fn (array $a) => $this->makeModel($a));
        $this->add('make:policy', 'Create an authorization policy [--model=Post]', fn (array $a) => $this->makePolicy($a));
        $this->add('make:migration', 'Create a migration (create_posts_table / add_x_to_posts_table)', fn (array $a) => $this->makeMigration($a));
        $this->add('make:seeder', 'Create a seeder', fn (array $a) => $this->makeFromStub($a, 'seeder', 'database/seeders', 'Seeder'));
        $this->add('make:factory', 'Create a model factory', fn (array $a) => $this->makeFactory($a));
        $this->add('make:middleware', 'Create a middleware', fn (array $a) => $this->makeFromStub($a, 'middleware', 'app/Http/Middleware'));
        $this->add('make:mail', 'Create a mailable and its email view', fn (array $a) => $this->makeMail($a));
        $this->add('make:job', 'Create a queued job', fn (array $a) => $this->makeFromStub($a, 'job', 'app/Jobs'));
        $this->add('make:command', 'Create a console command', fn (array $a) => $this->makeCommand($a));
        $this->add('make:provider', 'Create a service provider', fn (array $a) => $this->makeFromStub($a, 'provider', 'app/Providers', 'ServiceProvider'));
        $this->add('make:test', 'Create a feature test', fn (array $a) => $this->makeFromStub($a, 'test', 'tests/Feature', 'Test'));

        // cache & maintenance
        $this->add('cache:clear', 'Clear the application cache', function (): int {
            Cache::flush();
            Output::success('Application cache cleared.');

            return 0;
        });
        $this->add('view:clear', 'Clear compiled Blade views', fn () => $this->clearDirectory('storage/framework/views', 'Compiled views cleared.'));
        $this->add('optimize:clear', 'Clear all caches (app cache, views, rate limits)', function (): int {
            Cache::flush();
            $this->clearDirectory('storage/framework/views', 'Compiled views cleared.');
            $this->clearDirectory('storage/cache/ratelimit', 'Rate limits cleared.');

            return 0;
        });
        $this->add('down', 'Put the app into maintenance mode [--secret=xyz] [--message=...]', fn (array $a) => $this->down($a));
        $this->add('up', 'Bring the app out of maintenance mode', function (): int {
            @unlink($this->app->maintenanceFile());
            Output::success('Application is now live.');

            return 0;
        });

        // queues & scheduler
        $this->add('queue:work', 'Process queued jobs [--queue=default] [--once] [--sleep=3]', fn (array $a) => $this->queueWork($a));
        $this->add('queue:failed', 'List failed jobs', fn () => $this->queueFailed());
        $this->add('queue:retry', 'Retry failed jobs: queue:retry <id|all>', fn (array $a) => $this->queueRetry($a));
        $this->add('queue:flush', 'Delete all failed jobs', function (): int {
            DB::statement('DELETE FROM failed_jobs');
            Output::success('All failed jobs deleted.');

            return 0;
        });
        $this->add('schedule:run', 'Run due scheduled tasks (call every minute from cron)', fn () => $this->scheduleRun());
        $this->add('schedule:list', 'List scheduled tasks', fn () => $this->scheduleList());

        // integrations
        $this->add('mail:theme', 'Choose the email template: modern | classic | minimal', fn (array $a) => $this->mailTheme($a));
        $this->add('mail:test', 'Send a test email: mail:test you@example.com', fn (array $a) => $this->mailTest($a));
        $this->add('payment:install', 'Add a payment gateway: paystack | flutterwave | stripe | paypal | hubtel', fn (array $a) => $this->install($a, 'payment'));
        $this->add('payment:list', 'Show payment gateways and whether they are configured', fn () => $this->gatewayList('payment'));
        $this->add('sms:install', 'Add an SMS gateway: twilio | africastalking | hubtel | arkesel', fn (array $a) => $this->install($a, 'sms'));
        $this->add('sms:list', 'Show SMS gateways and whether they are configured', fn () => $this->gatewayList('sms'));
        $this->add('sms:test', 'Send a test SMS: sms:test 0241234567', fn (array $a) => $this->smsTest($a));

        $this->add('user:role', 'Give a user a role: user:role you@example.com admin', fn (array $a) => $this->userRole($a));
        $this->add('test', 'Run the test suite [filter]', fn (array $a) => (new TestRunner($this->app))->run($a[0] ?? null));
    }

    private function add(string $name, string $description, Closure $handler): void
    {
        $this->commands[$name] = ['description' => $description, 'handler' => $handler];
    }

    /** Framework commands implemented as Command classes. */
    private const CORE_COMMANDS = [
        Commands\KeyRotateCommand::class,
        Commands\DbBackupCommand::class,
        Commands\DbRestoreCommand::class,
        Commands\LogPruneCommand::class,
        Commands\SecurityScanCommand::class,
        Commands\LintCommand::class,
        Commands\DeployVerifyCommand::class,
    ];

    private function discoverCustomCommands(): void
    {
        foreach (self::CORE_COMMANDS as $class) {
            /** @var Command $command */
            $command = $this->app->make($class);
            $this->custom[$command->name()] = $command;
        }

        foreach (glob($this->app->basePath . '/app/Console/Commands/*.php') ?: [] as $file) {
            $class = 'App\\Console\\Commands\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                /** @var Command $command */
                $command = $this->app->make($class);
                $this->custom[$command->name()] = $command;
            }
        }
    }

    private function listCommands(): int
    {
        Output::title('INB PHP Framework ' . App::VERSION);
        Output::line('Usage: php console <command> [arguments] [--options]');
        Output::line();

        $all = [];
        foreach ($this->commands as $name => $command) {
            $all[$name] = $command['description'];
        }
        foreach ($this->custom as $name => $command) {
            $all[$name] = $command->description();
        }
        ksort($all);

        $group = null;
        foreach ($all as $name => $description) {
            $hasChildren = array_filter(array_keys($all), static fn ($n) => str_starts_with($n, $name . ':')) !== [];
            $prefix = str_contains($name, ':') ? (string) strtok($name, ':') : ($hasChildren ? $name : '');
            if ($prefix !== $group) {
                $group = $prefix;
                if ($prefix !== '') {
                    Output::line(Output::color('33', ' ' . $prefix));
                }
            }
            Output::line('  ' . Output::color('32', str_pad($name, 22)) . ' ' . $description);
        }

        return 0;
    }

    private function about(): int
    {
        $driver = Database::instance()->driver() . ' / ' . Database::instance()->getName();
        try {
            Database::instance()->pdo();
            $db = 'connected';
        } catch (Throwable $e) {
            $db = 'NOT connected — ' . $e->getMessage();
        }

        Output::table(['Setting', 'Value'], [
            ['Application', (string) Config::get('app.name')],
            ['Framework', 'INB PHP Framework ' . App::VERSION],
            ['PHP', PHP_VERSION],
            ['Environment', (string) Config::get('app.env')],
            ['Debug', Config::get('app.debug') ? 'ON' : 'off'],
            ['Database', "$driver ($db)"],
            ['Mail', (string) Config::get('mail.default') . ' / theme ' . (string) Config::get('mail.theme')],
            ['Payments', (string) (Config::get('payments.default') ?: 'none')],
            ['SMS', (string) Config::get('sms.default')],
            ['Queue', (string) Config::get('queue.default')],
            ['Compression', \Core\Compression\Compressor::algorithm() . (function_exists('imagewebp') ? ' + WebP images' : '')],
            ['Maintenance', $this->app->isDownForMaintenance() ? 'DOWN' : 'live'],
        ]);

        return 0;
    }

    /** @param string[] $args */
    private function serve(array $args): int
    {
        [$positional, $options] = Input::parse($args);
        $port = (int) ($options['port'] ?? $positional[0] ?? 8000);
        Output::success("INB PHP Framework development server: http://localhost:$port  (Ctrl+C to stop)");
        passthru(sprintf(
            '%s -S localhost:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($this->app->basePath . '/public'),
            escapeshellarg($this->app->basePath . '/public/index.php'),
        ), $code);

        return (int) $code;
    }

    /** @param string[] $args */
    private function keyGenerate(array $args): int
    {
        [, $options] = Input::parse($args);
        $env = $this->app->basePath . '/.env';
        $current = EnvEditor::get($env, 'APP_KEY');

        if ($current !== null && $current !== '' && !isset($options['force'])) {
            Output::warn('APP_KEY is already set. Changing it logs everyone out and invalidates encrypted data, tokens and signed links.');
            Output::line('Use --force if you are sure.');

            return 1;
        }

        EnvEditor::set($env, 'APP_KEY', Crypt::generateKey());
        Output::success('Application key set.');

        return 0;
    }

    private function routeList(): int
    {
        $rows = [];
        foreach ($this->app->router->routes() as $route) {
            $action = $route->getAction();
            $rows[] = [
                implode('|', array_diff($route->getMethods(), ['HEAD'])),
                $route->getUri(),
                (string) $route->getName(),
                is_array($action) ? class_basename($action[0]) . '@' . $action[1] : (is_string($action) ? $action : 'Closure'),
                implode(', ', $route->getMiddleware()),
            ];
        }
        Output::table(['Method', 'URI', 'Name', 'Action', 'Middleware'], $rows);

        return 0;
    }

    private function securityCheck(): int
    {
        $issues = [];
        $ok = [];
        $production = Config::get('app.env') === 'production';

        $check = static function (bool $pass, string $good, string $bad, bool $critical = true) use (&$issues, &$ok): void {
            if ($pass) {
                $ok[] = $good;
            } else {
                $issues[] = [$critical ? 'HIGH' : 'MEDIUM', $bad];
            }
        };

        $key = (string) Config::get('app.key', '');
        $check(str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7))) === 32, 'APP_KEY is set (256-bit)', 'APP_KEY is missing or weak — run php console key:generate');
        $check(!($production && Config::get('app.debug')), 'Debug mode is off in production', 'APP_DEBUG=true in production exposes code and data — set APP_DEBUG=false');
        $check(!$production || Config::get('session.secure'), 'Session cookie is HTTPS-only', 'Set SESSION_SECURE_COOKIE=true on the live (HTTPS) site', $production);
        $check(Config::get('firewall.enabled', true) === true, 'Request firewall is enabled', 'The request firewall is disabled (FIREWALL_ENABLED=false)');
        $check(Config::get('security.csp') !== [], 'Content-Security-Policy is enabled', 'Content-Security-Policy is disabled in config/security.php', false);
        $check(!in_array('*', (array) Config::get('cors.allowed_origins', []), true), 'CORS is limited to listed origins', 'CORS allows every origin (*) — list your front-end domains in config/cors.php', false);

        $dbConfig = Database::instance()->getConfig();
        $dbUser = (string) ($dbConfig['username'] ?? '');
        $isServerDb = !in_array(Database::instance()->driver(), ['sqlite'], true);
        $check(!in_array(strtolower($dbUser), ['root', 'sa', 'postgres', 'sys', 'system', 'sysdba'], true), 'The app does not use a database super-user', 'The app connects as "' . $dbUser . '" — create a dedicated user with access to this database only');
        $check(!$isServerDb || Database::instance()->hasPassword(), 'The database user has a password', 'The database password is empty');

        $check(is_dir($this->app->basePath . '/storage') && is_writable($this->app->basePath . '/storage'), 'storage/ is writable', 'storage/ is not writable by PHP');
        $check(is_file($this->app->basePath . '/public/uploads/.htaccess'), 'PHP execution is blocked in public/uploads', 'public/uploads/.htaccess is missing — uploaded scripts could run');
        $check(!is_file($this->app->basePath . '/public/.env') && !is_file($this->app->basePath . '/public/composer.json'), 'No secret files inside public/', 'Secret files found inside public/ — move them out');
        $check(ini_get('expose_php') !== '1', 'PHP version is hidden (expose_php=Off)', 'Set expose_php = Off in php.ini', false);
        // PHP security support end dates (https://www.php.net/supported-versions.php).
        $eol = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];
        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $end = $eol[$branch] ?? null;
        $daysLeft = $end === null ? null : (int) floor((strtotime($end) - time()) / 86400);
        $check($end === null ? PHP_VERSION_ID >= 80500 : $daysLeft >= 0, "PHP $branch is still receiving security fixes", "PHP $branch no longer receives security fixes (ended $end) — upgrade PHP");
        if ($daysLeft !== null && $daysLeft >= 0) {
            $check($daysLeft > 120, "PHP $branch is supported until $end", "PHP $branch security support ends $end ($daysLeft days) — plan the upgrade to 8.3/8.4", false);
        }
        $issues[] = ['INFO', 'Running PHP ' . PHP_VERSION . ' — make sure it is the latest patch release of the ' . $branch . ' branch.'];

        // Production readiness.
        $appUrl = (string) Config::get('app.url', '');
        $check(!$production || str_starts_with($appUrl, 'https://'), 'APP_URL is an https:// address', 'Set APP_URL to the canonical https:// address of the site (used for email links and the trusted-host check)', $production);
        $check(!$production || Config::get('mail.default') !== 'log', 'A real mail transport is configured', 'MAIL_MAILER=log in production writes reset/verification links to a file — use smtp', $production);
        $check(!$production || Config::get('queue.default') !== 'sync', 'Queued jobs run in the background', 'QUEUE_CONNECTION=sync in production: emails slow down requests — use database + a worker', false);
        $check(!$production || (string) Config::get('logging.alert_email', '') !== '', 'Error alerts are emailed', 'Set LOG_ALERT_EMAIL to be told about server errors', false);
        $check(!$production || (bool) Config::get('backup.schedule', false), 'Nightly database backups are scheduled', 'Set BACKUP_SCHEDULE=true (and test a restore)', false);
        $check(in_array('admin', (array) Config::get('auth.two_factor.required_roles', []), true), 'Administrators must use two-factor authentication', 'Add "admin" to AUTH_2FA_REQUIRED_ROLES', false);

        foreach ($ok as $line) {
            Output::success('  ✓ ' . $line);
        }
        foreach ($issues as [$level, $line]) {
            Output::line($level === 'INFO'
                ? Output::color('36', "  i $line")
                : Output::color($level === 'HIGH' ? '31' : '33', "  ✗ [$level] $line"));
        }
        $problems = array_filter($issues, static fn ($i) => $i[0] !== 'INFO');
        Output::line();
        Output::line($problems === [] ? Output::color('32', 'No problems found.') : count($problems) . ' problem(s) found.'
            . ($production ? '' : '  (APP_ENV is not "production": production-only checks were skipped)'));

        return count(array_filter($issues, static fn ($i) => $i[0] === 'HIGH')) > 0 ? 1 : 0;
    }

    /** @param string[] $args */
    private function migrate(string $action, array $args): int
    {
        [, $options] = Input::parse($args);
        $migrator = new Migrator($this->app->basePath . '/database/migrations', static fn (string $line) => Output::line($line));

        if (Config::get('app.env') === 'production' && in_array($action, ['fresh', 'reset'], true) && !isset($options['force'])) {
            Output::error("Refusing to run migrate:$action in production (it deletes data). Add --force if you really mean it.");

            return 1;
        }

        match ($action) {
            'run' => $migrator->run(),
            'rollback' => $migrator->rollback((int) ($options['step'] ?? 1)),
            'reset' => $migrator->reset(),
            'fresh' => $migrator->fresh(),
        };

        if (isset($options['seed'])) {
            return $this->seed([]);
        }

        return 0;
    }

    private function migrateStatus(): int
    {
        $migrator = new Migrator($this->app->basePath . '/database/migrations');
        $rows = [];
        foreach ($migrator->status() as $name => $status) {
            $rows[] = [$status === null ? Output::color('33', 'Pending') : Output::color('32', $status), $name];
        }
        Output::table(['Status', 'Migration'], $rows);

        return 0;
    }

    /** @param string[] $args */
    private function seed(array $args): int
    {
        [, $options] = Input::parse($args);
        $class = (string) ($options['class'] ?? 'DatabaseSeeder');
        $class = str_contains($class, '\\') ? $class : 'Database\\Seeders\\' . $class;

        if (!class_exists($class)) {
            Output::error("Seeder [$class] not found.");

            return 1;
        }

        /** @var \Core\Database\Seeder $seeder */
        $seeder = $this->app->make($class);
        $seeder->output = static fn (string $line) => Output::line($line);
        $seeder->run();
        Output::success('Database seeded.');

        return 0;
    }

    private function dbShow(): int
    {
        $dbConfig = Database::instance()->getConfig();
        Output::line('Connection: ' . Database::instance()->getName() . ' (' . Database::instance()->driver() . ')');
        Output::line('Database:   ' . (string) ($dbConfig['database'] ?? $dbConfig['service_name'] ?? ''));
        $rows = [];
        foreach (Schema::getTables() as $table) {
            $count = DB::selectOne('SELECT COUNT(*) AS c FROM ' . Database::instance()->quoteIdentifier($table))['c'] ?? 0;
            $rows[] = [$table, (string) $count];
        }
        Output::table(['Table', 'Rows'], $rows);

        return 0;
    }

    private function dbDrivers(): int
    {
        $available = \PDO::getAvailableDrivers();
        $rows = [];
        $databases = [
            ['MySQL', 'mysql', 'mysql', 'extension=pdo_mysql'],
            ['MariaDB', 'mariadb', 'mysql', 'extension=pdo_mysql'],
            ['PostgreSQL', 'pgsql', 'pgsql', 'extension=pdo_pgsql'],
            ['SQLite', 'sqlite', 'sqlite', 'extension=pdo_sqlite'],
            ['SQL Server / Azure SQL', 'sqlsrv', 'sqlsrv', 'Microsoft pdo_sqlsrv driver (or pdo_dblib on Linux)'],
            ['Oracle', 'oracle', 'oci', 'extension=pdo_oci + Oracle Instant Client'],
            ['Firebird', 'firebird', 'firebird', 'extension=pdo_firebird'],
            ['ODBC (Db2, Access, ...)', 'odbc', 'odbc', 'extension=pdo_odbc + the database ODBC driver'],
        ];

        foreach ($databases as [$label, $connection, $pdo, $install]) {
            $ok = in_array($pdo, $available, true) || ($pdo === 'sqlsrv' && in_array('dblib', $available, true));
            $rows[] = [$label, $connection, $ok ? Output::color('32', 'installed') : Output::color('33', 'not installed'), $ok ? '' : $install];
        }

        Output::table(['Database', 'DB_CONNECTION', 'PHP driver', 'To enable (php.ini)'], $rows);
        Output::line();
        Output::line('Default connection: ' . Config::get('database.default') . '   (set DB_CONNECTION in .env)');

        return 0;
    }

    private function dbTest(?string $name): int
    {
        $db = $name === null ? Database::instance() : Database::connection($name);
        $start = microtime(true);
        $db->pdo();
        $version = (string) $db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        Output::success(sprintf('Connected to [%s] (%s %s) in %d ms.', $db->getName(), $db->driver(), $version, (int) ((microtime(true) - $start) * 1000)));

        return 0;
    }

    // ------------------------------------------------------------------ generators

    /** @param string[] $args */
    private function makeController(array $args): int
    {
        [$positional, $options] = Input::parse($args);
        $name = $this->className($positional[0] ?? null, 'Controller', 'make:controller PostController');
        $base = substr($name, 0, -strlen('Controller'));
        $uri = Str::plural(Str::kebab($base));
        $stub = isset($options['api']) ? 'controller.api' : (isset($options['resource']) || isset($options['r']) ? 'controller.resource' : 'controller');
        $model = Str::studly(is_string($options['model'] ?? null) ? (string) $options['model'] : $base);

        $this->writeStub($stub, "app/Http/Controllers/$name.php", [
            'class' => $name,
            'view' => Str::snake($base),
            'uri' => $uri,
            'route' => str_replace('/', '.', $uri),
            'model' => $model,
            'variable' => Str::camel($model),
            'plural' => Str::camel(Str::plural($model)),
        ]);

        if ($stub === 'controller.resource') {
            if (!class_exists('App\\Models\\' . $model)) {
                Output::warn("Model App\\Models\\$model does not exist yet: php console make:model $model -m");
            }
            if (!is_file($this->app->basePath . "/app/Policies/{$model}Policy.php")) {
                $this->makePolicy([$model . 'Policy', '--model=' . $model]);
            }
            Output::line("Add the routes: \$router->resource('$uri', App\\Http\\Controllers\\$name::class);");
        }

        return 0;
    }

    /** @param string[] $args */
    private function makePolicy(array $args): int
    {
        [$positional, $options] = Input::parse($args);
        $name = $this->className($positional[0] ?? null, 'Policy', 'make:policy PostPolicy --model=Post');
        $model = Str::studly(is_string($options['model'] ?? null) ? (string) $options['model'] : substr($name, 0, -strlen('Policy')));

        $this->writeStub('policy', "app/Policies/$name.php", ['class' => $name, 'model' => $model, 'variable' => Str::camel($model)]);

        return 0;
    }

    /** @param string[] $args */
    private function makeModel(array $args): int
    {
        [$positional, $options] = Input::parse($args);
        $name = $this->className($positional[0] ?? null, '', 'make:model Post');
        $table = Str::plural(Str::snake($name));

        $this->writeStub('model', "app/Models/$name.php", ['class' => $name, 'table' => $table]);

        if (isset($options['m']) || isset($options['migration']) || isset($options['all'])) {
            $this->makeMigration(["create_{$table}_table"]);
        }
        if (isset($options['f']) || isset($options['factory']) || isset($options['all'])) {
            $this->makeFactory([$name . 'Factory']);
        }
        if (isset($options['s']) || isset($options['seed']) || isset($options['all'])) {
            $this->makeFromStub([$name . 'Seeder'], 'seeder', 'database/seeders', 'Seeder');
        }

        return 0;
    }

    /** @param string[] $args */
    private function makeMigration(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null) {
            Output::error('Usage: php console make:migration create_posts_table');

            return 1;
        }

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        if (preg_match('/^create_(\w+)_table$/', $slug, $m)) {
            $stub = 'migration.create';
            $table = $m[1];
        } else {
            $stub = 'migration.table';
            $table = preg_match('/_(?:to|from|in)_(\w+?)(?:_table)?$/', $slug, $m) ? $m[1] : 'table_name';
        }

        $this->writeStub($stub, 'database/migrations/' . date('Y_m_d_His') . "_$slug.php", ['table' => $table]);

        return 0;
    }

    /** @param string[] $args */
    private function makeFactory(array $args): int
    {
        $name = $this->className($args[0] ?? null, 'Factory', 'make:factory PostFactory');
        $this->writeStub('factory', "database/factories/$name.php", ['class' => $name, 'model' => substr($name, 0, -strlen('Factory'))]);

        return 0;
    }

    /** @param string[] $args */
    private function makeMail(array $args): int
    {
        $name = $this->className($args[0] ?? null, '', 'make:mail OrderShipped');
        $view = Str::kebab($name);
        $this->writeStub('mail', "app/Mail/$name.php", ['class' => $name, 'view' => $view, 'subject' => ucfirst(strtolower(trim((string) preg_replace('/([A-Z])/', ' $1', $name))))]);
        $this->writeStub('mail.view', "resources/views/emails/$view.blade.php", []);

        return 0;
    }

    /** @param string[] $args */
    private function makeCommand(array $args): int
    {
        $name = $this->className($args[0] ?? null, '', 'make:command SendReminders');
        $this->writeStub('command', "app/Console/Commands/$name.php", ['class' => $name, 'name' => 'app:' . Str::kebab($name)]);

        return 0;
    }

    /** @param string[] $args */
    private function makeFromStub(array $args, string $stub, string $directory, string $suffix = ''): int
    {
        $name = $this->className($args[0] ?? null, $suffix, "make:$stub Example$suffix");
        $this->writeStub($stub, "$directory/$name.php", ['class' => $name]);

        return 0;
    }

    private function className(?string $name, string $suffix, string $usage): string
    {
        if ($name === null || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Usage: php console $usage");
        }
        $name = Str::studly($name);

        return $suffix !== '' && !str_ends_with($name, $suffix) ? $name . $suffix : $name;
    }

    /** @param array<string, string> $replace */
    private function writeStub(string $stub, string $relativePath, array $replace): void
    {
        $target = $this->app->basePath . '/' . $relativePath;
        if (file_exists($target)) {
            throw new \RuntimeException("File already exists: $relativePath");
        }

        $custom = $this->app->basePath . "/stubs/$stub.stub";
        $source = is_file($custom) ? $custom : __DIR__ . "/../stubs/$stub.stub";
        $contents = (string) file_get_contents($source);
        foreach ($replace as $key => $value) {
            $contents = str_replace('{{ ' . $key . ' }}', $value, $contents);
        }

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $contents);
        Output::success("Created $relativePath");
    }

    // ------------------------------------------------------------------ maintenance & queues

    private function clearDirectory(string $relative, string $message): int
    {
        foreach (glob($this->app->basePath . '/' . $relative . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitignore') {
                @unlink($file);
            }
        }
        Output::success($message);

        return 0;
    }

    /** @param string[] $args */
    private function down(array $args): int
    {
        [, $options] = Input::parse($args);
        $secret = is_string($options['secret'] ?? null) ? (string) $options['secret'] : '';
        if ($secret !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $secret)) {
            Output::error('The secret must be 8–64 letters, numbers, "-" or "_".');

            return 1;
        }

        $file = $this->app->maintenanceFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, (string) json_encode([
            'time' => time(),
            'secret' => $secret,
            'message' => is_string($options['message'] ?? null) ? $options['message'] : null,
            'retry' => (int) ($options['retry'] ?? 60),
        ]));

        Output::warn('Application is now in maintenance mode.');
        if ($secret !== '') {
            Output::line('Bypass it by visiting: ' . url('/' . $secret));
        }

        return 0;
    }

    /** @param string[] $args */
    private function queueWork(array $args): int
    {
        [, $options] = Input::parse($args);
        $queue = is_string($options['queue'] ?? null) ? $options['queue'] : 'default';
        $sleep = max(1, (int) ($options['sleep'] ?? 3));
        Output::info("Processing jobs on the [$queue] queue. Press Ctrl+C to stop.");

        $lastBeat = 0;
        do {
            if (time() - $lastBeat >= 30) {
                \Core\Support\Heartbeat::beat('queue');   // checked by deploy:verify
                $lastBeat = time();
            }
            $worked = Queue::work($queue, static fn (string $line) => Output::line(date('H:i:s') . ' ' . $line));
            if (isset($options['once']) || isset($options['stop-when-empty']) && !$worked) {
                break;
            }
            if (!$worked) {
                sleep($sleep);
            }
        } while (true);

        return 0;
    }

    private function queueFailed(): int
    {
        $rows = [];
        foreach (DB::table('failed_jobs')->orderBy('id', 'DESC')->limit(50)->get() as $job) {
            $rows[] = [(string) $job['id'], \Core\Queue\Payload::jobName((string) $job['payload']) ?? '?', (string) $job['failed_at'], Str::limit(strtok((string) $job['exception'], "\n") ?: '', 60)];
        }
        Output::table(['ID', 'Job', 'Failed at', 'Error'], $rows);

        return 0;
    }

    /** @param string[] $args */
    private function queueRetry(array $args): int
    {
        $id = $args[0] ?? null;
        $query = DB::table('failed_jobs');
        if ($id !== 'all') {
            if ($id === null || !ctype_digit($id)) {
                Output::error('Usage: php console queue:retry <id|all>');

                return 1;
            }
            $query->where('id', (int) $id);
        }

        $count = 0;
        foreach ($query->get() as $failed) {
            DB::table('jobs')->insert([
                'queue' => $failed['queue'], 'payload' => $failed['payload'], 'attempts' => 0,
                'available_at' => time(), 'reserved_at' => null, 'created_at' => time(),
            ]);
            DB::table('failed_jobs')->where('id', $failed['id'])->delete();
            $count++;
        }
        Output::success("$count job(s) pushed back onto the queue.");

        return 0;
    }

    private function scheduleRun(): int
    {
        \Core\Support\Heartbeat::beat('scheduler');   // checked by deploy:verify
        $due = $this->schedule->dueEvents(new \DateTimeImmutable());
        if ($due === []) {
            Output::line('No scheduled tasks are due.');

            return 0;
        }

        foreach ($due as $event) {
            Output::line('Running: ' . $event->getDescription());
            try {
                match ($event->type) {
                    'closure' => ($event->target)(),
                    'job' => Queue::push(clone $event->target),
                    'command' => $this->handle(['console', $event->target[0], ...$event->target[1]]),
                };
            } catch (Throwable $e) {
                \Core\ErrorHandler::report($e);
                Output::error('  Failed: ' . $e->getMessage());
            }
        }

        return 0;
    }

    private function scheduleList(): int
    {
        $rows = array_map(static fn (ScheduledEvent $e) => [$e->getExpression(), $e->getDescription()], $this->schedule->events());
        Output::table(['Cron', 'Task'], $rows);

        return 0;
    }

    // ------------------------------------------------------------------ integrations

    /** @param string[] $args */
    private function mailTheme(array $args): int
    {
        $theme = strtolower($args[0] ?? '');
        if (!in_array($theme, self::MAIL_THEMES, true)) {
            Output::line('Available email templates:');
            Output::line('  modern   — gradient header, rounded card, bold buttons (default)');
            Output::line('  classic  — corporate letter style with serif headings and sign-off');
            Output::line('  minimal  — clean, text-first, lightweight (best deliverability)');
            Output::line();
            Output::line('Usage: php console mail:theme modern');

            return $theme === '' ? 0 : 1;
        }

        $target = $this->app->basePath . '/resources/views/emails/layout.blade.php';
        if (is_file($target) && !in_array('--force', $args, true)) {
            $backup = $target . '.bak';
            copy($target, $backup);
            Output::line(Output::muted('Previous layout saved as resources/views/emails/layout.blade.php.bak'));
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy(__DIR__ . "/../stubs/mail-themes/$theme.blade.php", $target);
        EnvEditor::set($this->app->basePath . '/.env', 'MAIL_THEME', $theme);
        Output::success("Email template \"$theme\" installed. Customise it in resources/views/emails/layout.blade.php");

        return 0;
    }

    /** @param string[] $args */
    private function mailTest(array $args): int
    {
        $to = $args[0] ?? '';
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Output::error('Usage: php console mail:test you@example.com');

            return 1;
        }

        Mail::to($to)->send(new \App\Mail\TestMail());
        Output::success("Test email sent to $to using the \"" . Config::get('mail.default') . '" mailer.');
        if (Config::get('mail.default') === 'log') {
            Output::line('MAIL_MAILER=log: open storage/logs/mail.log to see it. Set MAIL_MAILER=smtp to really send.');
        }

        return 0;
    }

    /** @param string[] $args */
    private function install(array $args, string $kind): int
    {
        $catalog = $kind === 'payment' ? self::PAYMENT_ENV : self::SMS_ENV;
        $name = strtolower($args[0] ?? '');

        if (!isset($catalog[$name])) {
            Output::line('Available ' . $kind . ' gateways: ' . implode(', ', array_keys($catalog)));
            Output::line("Usage: php console $kind:install " . array_key_first($catalog));

            return $name === '' ? 0 : 1;
        }

        $envFiles = [$this->app->basePath . '/.env', $this->app->basePath . '/.env.example'];
        $label = ($kind === 'payment' ? 'Payment' : 'SMS') . ' gateway: ' . $name;
        $added = [];
        foreach ($envFiles as $file) {
            foreach ($catalog[$name] as $key => $default) {
                if (EnvEditor::add($file, $key, $default, $label) && str_ends_with($file, '.env')) {
                    $added[] = $key;
                }
            }
        }

        EnvEditor::set($this->app->basePath . '/.env', $kind === 'payment' ? 'PAYMENT_GATEWAY' : 'SMS_GATEWAY', $name);

        Output::success(($kind === 'payment' ? 'Payment' : 'SMS') . " gateway \"$name\" installed and set as default.");
        Output::line('Now fill in your credentials in .env:');
        foreach (array_keys($catalog[$name]) as $key) {
            Output::line('  ' . $key . (in_array($key, $added, true) ? '' : Output::muted(' (already present)')));
        }
        if ($kind === 'payment') {
            Output::line('Webhook URL to register with the provider: ' . Output::color('36', rtrim((string) Config::get('app.url', 'https://your-site.com'), '/') . '/payments/webhook/' . $name));
        }

        return 0;
    }

    private function gatewayList(string $kind): int
    {
        $catalog = $kind === 'payment' ? self::PAYMENT_ENV : self::SMS_ENV;
        $default = (string) Config::get($kind === 'payment' ? 'payments.default' : 'sms.default');
        $rows = [];
        foreach ($catalog as $name => $keys) {
            $missing = array_filter(array_keys($keys), static fn ($k) => (string) env($k, '') === '');
            $rows[] = [$name, $name === $default ? 'default' : '', $missing === [] ? Output::color('32', 'configured') : Output::color('33', 'missing: ' . implode(', ', $missing))];
        }
        Output::table(['Gateway', '', 'Credentials'], $rows);

        return 0;
    }

    /** @param string[] $args */
    private function smsTest(array $args): int
    {
        $number = $args[0] ?? '';
        if ($number === '') {
            Output::error('Usage: php console sms:test 0241234567');

            return 1;
        }

        $result = Sms::send($number, 'Test message from ' . Config::get('app.name') . ' (INB PHP Framework).');
        Output::success('SMS ' . ($result->successful ? 'sent' : 'partly sent') . ' via ' . $result->gateway . '. IDs: ' . implode(', ', $result->messageIds));

        return 0;
    }

    /** @param string[] $args */
    private function userRole(array $args): int
    {
        [$email, $role] = [$args[0] ?? '', $args[1] ?? ''];
        if ($email === '' || !preg_match('/^[a-z][a-z0-9_-]{1,30}$/', $role)) {
            Output::error('Usage: php console user:role you@example.com admin');

            return 1;
        }

        $model = (string) Config::get('auth.model');
        $user = $model::where('email', strtolower($email))->first();
        if ($user === null) {
            Output::error("No user with email $email.");

            return 1;
        }

        $user->forceFill(['role' => $role])->save();
        Output::success("$email now has the role \"$role\".");

        return 0;
    }

    private function suggest(string $name): ?string
    {
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ([...array_keys($this->commands), ...array_keys($this->custom)] as $candidate) {
            $score = levenshtein($name, $candidate);
            if ($score < $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $bestScore <= 3 ? $best : null;
    }
}
