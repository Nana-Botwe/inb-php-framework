<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config;
use Core\Console\Command;
use Core\Console\Output;
use Core\Database;
use Core\Database\Migrator;
use Core\DB;
use Core\Support\Heartbeat;
use Throwable;

/**
 * Verifies that THIS server is really ready for production — run it after every deployment:
 *
 *   php console deploy:verify
 *   php console deploy:verify --url=https://www.example.com          # also probe the live site from outside
 *   php console deploy:verify --restore-test=restore_check           # restore the newest backup into that
 *                                                                    # connection and compare it
 *   php console deploy:verify --skip-heartbeats --skip-backups       # CI: no worker, scheduler or backups
 *
 * Checks: production settings (security:check), database + migrations, writable folders, web-server
 * rule files, scheduler and queue-worker heartbeats, queue backlog and failed jobs, backup age and
 * integrity (optionally a real restore), payments waiting for fulfilment. Exit code 1 if anything FAILS.
 */
final class DeployVerifyCommand extends Command
{
    protected string $signature = 'deploy:verify {--url=} {--restore-test=} {--skip-heartbeats} {--skip-backups}';

    protected string $description = 'Verify production readiness: config, migrations, worker, scheduler, backups (+ restore test)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        Output::title('Deployment verification — ' . Config::get('app.name', 'App') . ' on ' . gethostname());

        $this->section('Configuration');
        $this->configuration();

        $this->section('Database');
        $this->database();

        $this->section('Files and web server');
        $this->files();

        if (!$this->option('skip-heartbeats')) {
            $this->section('Background processes');
            $this->background();
        }

        if (!$this->option('skip-backups')) {
            $this->section('Backups');
            $this->backups();
        }

        $this->section('Payments');
        $this->payments();

        $url = $this->option('url');
        if (is_string($url) && $url !== '') {
            $this->section('Live site (from outside)');
            [$code, $output] = $this->console(['security:scan', $url]);
            $this->result($code === 0, 'security:scan ' . $url . ' found no exposed files or missing headers', "security:scan reported problems:\n" . $this->indent($output));
        }

        Output::line();
        if ($this->failures === 0) {
            $this->info('Deployment verified' . ($this->warnings > 0 ? " with {$this->warnings} warning(s)." : '.'));

            return self::SUCCESS;
        }
        $this->error("{$this->failures} check(s) failed, {$this->warnings} warning(s). Fix the failures before going live.");

        return self::FAILURE;
    }

    private function configuration(): void
    {
        $production = Config::get('app.env') === 'production';
        $this->result($production, 'APP_ENV=production', 'APP_ENV is "' . Config::get('app.env') . '" — this .env is not a production configuration (start from .env.production.example)');
        $this->result(!Config::get('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG=true shows code and data to visitors');

        [$code, $output] = $this->console(['security:check']);
        $this->result($code === 0, 'security:check reports no HIGH problems', "security:check found HIGH problems:\n" . $this->indent($output));
        $mediums = substr_count($output, '[MEDIUM]');
        if ($code === 0 && $mediums > 0) {
            $this->warning("security:check lists $mediums MEDIUM item(s) — run it to see them");
        }
    }

    private function database(): void
    {
        try {
            Database::instance()->selectOne('SELECT 1 AS ok');
            $this->result(true, 'Connected to the ' . Database::instance()->driver() . ' database', '');
        } catch (Throwable $e) {
            $this->result(false, '', 'Cannot connect to the database: ' . $e->getMessage());

            return;
        }

        try {
            $pending = array_keys(array_filter((new Migrator(base_path('database/migrations')))->status(), static fn ($s) => $s === null));
            $this->result($pending === [], 'All migrations have run', count($pending) . ' pending migration(s): ' . implode(', ', $pending) . ' — run php console migrate');
        } catch (Throwable $e) {
            $this->result(false, '', 'Could not read the migration status: ' . $e->getMessage());
        }
    }

    private function files(): void
    {
        foreach (['storage/logs', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/app', 'public/uploads'] as $dir) {
            $path = base_path($dir);
            $this->result(is_dir($path) && is_writable($path), "$dir is writable", "$dir is missing or not writable by PHP");
        }

        $this->result(is_file(base_path('public/uploads/.htaccess')), 'Script execution is blocked in public/uploads (.htaccess)', 'public/uploads/.htaccess is missing');
        $this->result(is_file(base_path('.htaccess')) && is_file(base_path('public/.htaccess')), 'Apache rule files are present (.htaccess, public/.htaccess)', 'An .htaccess file is missing — on Nginx use deploy/nginx.conf instead');
        $this->result(!is_file(base_path('public/.env')) && !is_file(base_path('public/composer.json')), 'No secret files inside public/', 'Secret files found inside public/');

        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot === '') {
            $this->warning('The document root can\'t be seen from the console — confirm it points to public/ (or use --url to probe the site)');
        }
    }

    private function background(): void
    {
        $scheduler = Heartbeat::age('scheduler');
        $this->result($scheduler !== null && $scheduler <= 180,
            'Scheduler ran ' . $scheduler . ' s ago',
            $scheduler === null ? 'The scheduler has never run — add the cron entry / timer from deploy/ (php console schedule:run every minute)' : "The scheduler last ran {$scheduler} s ago — it should run every minute");

        if (Config::get('queue.default', 'sync') === 'sync') {
            $this->warning('QUEUE_CONNECTION=sync — emails are sent inside web requests; use database + a worker in production');

            return;
        }

        $queue = Heartbeat::age('queue');
        $this->result($queue !== null && $queue <= 180,
            'A queue worker was alive ' . $queue . ' s ago',
            $queue === null ? 'No queue worker has ever run — start one with deploy/supervisor-queue.conf (php console queue:work)' : "The queue worker was last seen {$queue} s ago — is it still running?");

        try {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', time())->orderBy('available_at')->first();
            $waiting = $oldest === null ? 0 : time() - (int) $oldest['available_at'];
            $this->result($waiting <= 900, 'Queue backlog is healthy' . ($oldest === null ? ' (empty)' : " (oldest job waiting {$waiting} s)"), "The oldest job has been waiting {$waiting} s — the worker is not keeping up or is stopped");

            $failed = DB::table('failed_jobs')->count();
            if ($failed > 0) {
                $this->warning("$failed failed job(s) — php console queue:failed");
            }
        } catch (Throwable $e) {
            $this->result(false, '', 'Could not read the jobs tables: ' . $e->getMessage());
        }
    }

    private function backups(): void
    {
        $this->result((bool) Config::get('backup.schedule', false), 'Nightly backups are scheduled (BACKUP_SCHEDULE=true)', 'BACKUP_SCHEDULE is not true — no automatic backups');

        $db = Database::instance();
        $files = glob(storage_path('backups/' . $db->getName() . '-*.gz')) ?: [];
        rsort($files);
        $newest = $files[0] ?? null;

        if ($newest === null) {
            $this->result(false, '', 'No backup found in storage/backups — run php console db:backup');

            return;
        }

        $ageHours = (time() - (int) filemtime($newest)) / 3600;
        $this->result($ageHours <= 26, sprintf('Newest backup %s is %.1f h old', basename($newest), $ageHours), sprintf('Newest backup %s is %.1f h old (more than a day)', basename($newest), $ageHours));

        [$valid, $reason] = $this->inspectBackup($newest);
        $this->result($valid, 'Backup file is complete and readable', 'Backup ' . basename($newest) . ' is damaged or incomplete: ' . $reason);

        $target = $this->option('restore-test');
        if (!is_string($target) || $target === '') {
            $this->warning('Restore not tested — add --restore-test=<connection> (a separate, empty database) to prove the backup restores');

            return;
        }
        if ($valid) {
            $this->restoreTest($newest, $target);
        }
    }

    /** @return array{0: bool, 1: string} */
    private function inspectBackup(string $file): array
    {
        $in = @gzopen($file, 'rb');
        if ($in === false) {
            return [false, 'cannot open as gzip'];
        }

        $head = '';
        $tail = '';
        $sawCreate = false;
        while (!gzeof($in)) {
            $chunk = gzread($in, 1 << 16);
            if ($chunk === false) {
                gzclose($in);

                return [false, 'decompression failed'];
            }
            if ($head === '') {
                $head = substr($chunk, 0, 64);
            }
            $sawCreate = $sawCreate || stripos($tail . $chunk, 'CREATE TABLE') !== false;
            $tail = substr($tail . $chunk, -4096);
        }
        gzclose($in);

        if (str_ends_with($file, '.sqlite.gz')) {
            return str_starts_with($head, "SQLite format 3\0") ? [true, ''] : [false, 'not an SQLite database file'];
        }
        if (!$sawCreate) {
            return [false, 'no CREATE TABLE statements'];
        }
        if (str_contains($tail, '-- Dump completed') || str_contains($tail, 'PostgreSQL database dump complete')) {
            return [true, ''];
        }

        return [false, 'the dump has no completion marker (it was cut off)'];
    }

    private function restoreTest(string $file, string $connection): void
    {
        try {
            $target = Database::connection($connection);
            $targetName = (string) ($target->getConfig()['database'] ?? '');
        } catch (Throwable $e) {
            $this->result(false, '', "Connection [$connection] is not configured in config/database.php: " . $e->getMessage());

            return;
        }

        $main = Database::instance();
        if ($connection === $main->getName() || $targetName === (string) ($main->getConfig()['database'] ?? '')
            || (stripos($targetName, 'restore') === false && stripos($targetName, 'test') === false)) {
            $this->result(false, '', 'The restore-test database must be separate from the live one and its name must contain "restore" or "test" (it is overwritten)');

            return;
        }

        [$code, $output] = $this->console(['db:restore', $file, $connection, '--force']);
        if ($code !== 0) {
            $this->result(false, '', "Restoring into [$connection] failed:\n" . $this->indent($output));

            return;
        }

        try {
            $expected = (int) $main->selectOne('SELECT COUNT(*) AS n FROM migrations')['n'];
            $restored = (int) $target->selectOne('SELECT COUNT(*) AS n FROM migrations')['n'];
            $users = (int) $target->selectOne('SELECT COUNT(*) AS n FROM users')['n'];
            $this->result($restored > 0 && $restored <= $expected,
                "Backup restored into [$connection]: $restored migrations, $users users",
                "Restored database looks wrong: $restored migrations (live has $expected)");
        } catch (Throwable $e) {
            $this->result(false, '', 'The restored database could not be read: ' . $e->getMessage());
        }
    }

    private function payments(): void
    {
        try {
            $unfulfilled = DB::table('payments')->where('status', 'success')->whereNull('fulfilled_at')
                ->where('paid_at', '<', date('Y-m-d H:i:s', time() - 900))->count();
            $this->result($unfulfilled === 0, 'No paid payment is waiting for fulfilment', "$unfulfilled paid payment(s) not fulfilled for 15+ minutes — php console payments:fulfil");

            $stuck = DB::table('payment_webhook_events')->whereIn('status', ['failed', 'unmatched'])->where('attempts', '>=', 20)->count();
            if ($stuck > 0) {
                $this->warning("$stuck webhook event(s) gave up after 20 attempts — check payment_webhook_events");
            }
        } catch (Throwable $e) {
            $this->warning('Payment tables not readable (migrations not run?): ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @param string[] $args @return array{0: int, 1: string} exit code and output */
    private function console(array $args): array
    {
        $process = proc_open([PHP_BINARY, base_path('console'), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (!is_resource($process)) {
            return [1, 'Could not start ' . PHP_BINARY];
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $output)];
    }

    private function section(string $title): void
    {
        Output::line();
        Output::line(Output::color('1', $title));
    }

    private function result(bool $pass, string $good, string $bad): void
    {
        if ($pass) {
            Output::line(Output::color('32', '  ✓ ') . $good);

            return;
        }
        $this->failures++;
        Output::line(Output::color('31', '  ✗ ') . $bad);
    }

    private function warning(string $message): void
    {
        $this->warnings++;
        Output::line(Output::color('33', '  ! ') . $message);
    }

    private function indent(string $text): string
    {
        $lines = array_filter(array_map('rtrim', explode("\n", $text)), static fn ($l) => $l !== '' && (str_contains($l, '✗') || str_contains($l, 'rror') || str_contains($l, 'EXPOSED')));

        return '      ' . implode("\n      ", array_slice($lines ?: [trim($text)], 0, 15));
    }
}
