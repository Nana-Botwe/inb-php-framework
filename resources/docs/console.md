# Console commands

The `console` tool (like Laravel's Artisan) runs from the project folder:

```bash
php console list
```

## General

| Command | Purpose |
|---|---|
| `about` | framework, PHP, database, mail, payments, SMS, queue and compression status |
| `serve [--port=8000]` | development server |
| `key:generate [--force]` | create `APP_KEY` |
| `route:list` | all routes with names, actions and middleware |
| `security:check` | audit the configuration for security problems |
| `down [--secret=xyz] [--message=...] [--retry=60]` | maintenance mode (visit `/xyz` once to bypass) |
| `up` | leave maintenance mode |
| `cache:clear`, `view:clear`, `optimize:clear` | clear caches |
| `test [filter]` | run the test suite |
| `user:role email role` | change a user's role |
| `key:rotate` | new `APP_KEY`, old one kept in `APP_PREVIOUS_KEYS` |
| `security:scan [url]` | probe a running site (default `APP_URL`) for exposed files (`.env`, `.git`, `composer.json`, backups…) and missing security headers |
| `deploy:verify [--url=] [--restore-test=]` | production readiness: config, migrations, worker, scheduler, backups (+ real restore), payments |
| `lint` | PHP syntax check of the whole project |
| `log:prune` | delete logs older than the retention period |

## Database

| Command | Purpose |
|---|---|
| `migrate` | run pending migrations |
| `migrate:status` | which migrations have run |
| `migrate:rollback [--step=N]` | undo the last batch(es) |
| `migrate:reset` | undo all |
| `migrate:fresh [--seed]` | drop all tables and migrate again |
| `db:seed [--class=Name]` | run seeders |
| `db:show` | tables and row counts |
| `db:drivers` | supported databases and which PHP drivers are installed |
| `db:test [connection]` | connect and show the server version |
| `db:backup` | dump the database to `storage/backups` (keeps the newest N) |
| `db:restore file --force` | restore a backup |

## Generators

| Command | Creates |
|---|---|
| `make:controller Name [--resource] [--api]` | `app/Http/Controllers/NameController.php` |
| `make:model Name [-m] [-f] [-s] [--all]` | model (+ migration, factory, seeder) |
| `make:migration create_x_table` / `add_y_to_x_table` | migration |
| `make:seeder`, `make:factory` | seeder / factory |
| `make:policy Name [--model=Post]` | authorization policy |
| `make:middleware` | middleware |
| `make:mail Name` | mailable + email view |
| `make:job` | queued job |
| `make:command Name` | console command |
| `make:provider` | service provider |
| `make:test` | feature test |

Customise any generated file by copying `core/stubs/{name}.stub` to `stubs/{name}.stub` in your project root.

## Queues & scheduler

`queue:work [--queue=] [--once] [--sleep=3] [--stop-when-empty]`, `queue:failed`, `queue:retry id|all`, `queue:flush`, `schedule:run`, `schedule:list`.

## Integrations

| Command | Purpose |
|---|---|
| `mail:theme modern\|classic\|minimal` | choose the email template |
| `mail:test you@example.com` | send a test email |
| `payment:install paystack\|flutterwave\|stripe\|paypal\|hubtel` | add a payment gateway |
| `payment:list` | gateway status |
| `payments:reconcile` | re-check pending payments and stored webhooks, finish interrupted fulfilment (scheduled every 5 minutes) |
| `payments:fulfil [id]` | list paid payments waiting for fulfilment, or retry one |
| `sms:install twilio\|africastalking\|hubtel\|arkesel` | add an SMS gateway |
| `sms:list`, `sms:test 0241234567` | SMS status / test |

## Writing commands

```bash
php console make:command SendReminders
```

```php
final class SendReminders extends Command
{
    protected string $signature = 'reminders:send {days=3} {--dry-run}';
    protected string $description = 'Email customers with unpaid invoices';

    public function handle(): int
    {
        $days = (int) $this->argument('days');
        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing will be sent.');
        }
        $this->table(['Invoice', 'Customer'], [['INV-1', 'Ama Mensah']]);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
```

Files in `app/Console/Commands` are discovered automatically: `php console reminders:send 7 --dry-run`.

Quick closure commands go in `routes/console.php` — see [Task scheduling](scheduling#closure-commands).

Errors in commands are shown in colour with the suggested fix, and logged.
