# Task scheduling

Define recurring tasks in `routes/console.php` and add **one** cron entry (or Windows scheduled task) that runs every minute.

## Defining tasks

```php
// routes/console.php
$schedule->command('cache:clear')->daily();
$schedule->command('queue:work', ['--stop-when-empty'])->everyMinute();
$schedule->job(new SendWeeklyReport())->weeklyOn(1, '08:00');          // Monday 08:00
$schedule->call(fn () => DB::table('carts')->where('updated_at', '<', date('Y-m-d', strtotime('-30 days')))->delete())
    ->dailyAt('02:30')
    ->description('Delete abandoned carts');
```

| Method | Runs |
|---|---|
| `everyMinute()`, `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()` | as named |
| `hourly()`, `hourlyAt(15)` | every hour (at :15) |
| `daily()`, `dailyAt('13:00')`, `twiceDaily(1, 13)` | every day |
| `weekly()`, `weeklyOn(1, '08:00')` | every week (0 = Sunday) |
| `monthly()`, `monthlyOn(1, '09:00')` | every month |
| `weekdays()` | Monday–Friday only (combine with the above) |
| `cron('0 9-17 * * 1-5')` | any cron expression |

Times use `APP_TIMEZONE`.

The framework already schedules clean-up of expired password reset tokens and API tokens.

```bash
php console schedule:list
php console schedule:run      # what cron calls
```

## Running the scheduler

**Linux (crontab -e):**

```text
* * * * * cd /var/www/my-shop && php console schedule:run >> /dev/null 2>&1
```

**Windows (XAMPP):** in Task Scheduler create a task that runs every minute:

```text
Program:   H:\xampp\php\php.exe
Arguments: console schedule:run
Start in:  H:\xampp\htdocs\my-shop
```

**cPanel:** Cron Jobs → every minute → `cd /home/user/my-shop && php console schedule:run`.

## Closure commands

```php
$console->command('report:today', function () {
    Core\Console\Output::info('Orders today: ' . DB::table('orders')->where('created_at', '>=', date('Y-m-d'))->count());
}, 'Show today\'s order count');
```
