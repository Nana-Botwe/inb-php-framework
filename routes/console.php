<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Console commands & task scheduling
|--------------------------------------------------------------------------
| Quick commands:  $console->command('name {arg}', fn ($arg) => ..., 'description');
| Scheduled tasks: run "php console schedule:run" every minute (cron / Windows Task Scheduler).
|
| @var Core\Console\Kernel $console
| @var Core\Console\Schedule $schedule
*/

use Core\Console\Output;
use Core\DB;

$console->command('inspire', function (): int {
    $quotes = [
        'Simplicity is the soul of efficiency. — Austin Freeman',
        'Make it work, make it right, make it fast. — Kent Beck',
        'Security is a process, not a product. — Bruce Schneier',
    ];
    Output::info($quotes[array_rand($quotes)]);

    return 0;
}, 'Display an inspiring quote');

// Clean expired password reset tokens every day.
$schedule->call(function (): void {
    DB::table('password_reset_tokens')->where('created_at', '<', date('Y-m-d H:i:s', time() - 3600))->delete();
})->daily()->description('Delete expired password reset tokens');

// Payments: retry stored webhooks, re-check pending payments, close expired ones.
$schedule->command('payments:reconcile')->everyFiveMinutes();

// Housekeeping: expired rate-limit entries and cache items, old logs.
$schedule->call(fn () => Core\RateLimiter::prune())->hourly()->description('Prune expired rate-limit entries');
$schedule->call(fn () => Core\Cache::prune())->hourly()->description('Prune expired cache entries');
$schedule->command('log:prune')->dailyAt('03:00');

// Nightly database backup (BACKUP_SCHEDULE=true).
if (config('backup.schedule')) {
    $schedule->command('db:backup')->dailyAt('02:00');
}

// Delete API tokens that have expired.
$schedule->call(function (): void {
    DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
})->daily()->description('Delete expired API tokens');
