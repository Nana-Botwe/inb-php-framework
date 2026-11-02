# Operations & maintenance

Running a site safely is more than writing code. This page covers backups, logs, alerts, background workers, payment reconciliation, key rotation, releases and rollback.

## Backups

```bash
php console db:backup                    # default connection -> storage/backups/<name>-<date>.sql.gz
php console db:backup reporting --keep=30
php console db:restore storage/backups/mysql-20261020-020000.sql.gz --force
```

| Database | How the backup is made |
|---|---|
| MySQL / MariaDB | `mysqldump --single-transaction` (consistent, no locking) |
| PostgreSQL | `pg_dump --clean --if-exists` |
| SQLite | online `VACUUM INTO` copy |
| Others | use the database's own tool (SQL Server backups, Oracle RMAN/Data Pump) |

- Passwords are passed by environment variable, never on the command line (where other users could see them).
- Files are gzip-compressed, readable only by their owner, and stored in `storage/backups` — outside the web root.
- `BACKUP_KEEP` (default 14) old backups are kept per connection.
- Turn on the nightly backup with `BACKUP_SCHEDULE=true`.
- On XAMPP set the tool paths: `BACKUP_MYSQLDUMP="H:\xampp\mysql\bin\mysqldump.exe"` and `BACKUP_MYSQL="H:\xampp\mysql\bin\mysql.exe"`.

> **Warning:** A backup you have never restored is not a backup. Restore into a test database regularly (`db:restore ... --force` on a copy) and copy backups **off the server** (another machine or cloud storage), because a server failure or ransomware takes local copies with it.

Also back up `storage/app/private` (uploaded documents) and keep `.env` / `APP_KEY` in a password manager — without the key, encrypted data can't be read.

## Logs

| File | Contents | Kept |
|---|---|---|
| `storage/logs/app-YYYY-MM-DD.log` | errors (with error ID, request, user, trace), app events | `LOG_RETENTION_DAYS` (30) |
| `storage/logs/security-YYYY-MM-DD.log` | logins, lockouts, 2FA, CSRF failures, firewall blocks and bans, forbidden pages, credential revocations, webhook rejections | 30 days |
| `storage/logs/mail.log`, `sms.log` | messages when using the log drivers (contain reset links!) | `LOG_SENSITIVE_RETENTION_DAYS` (3) |

`php console log:prune` (scheduled daily) applies the retention. Logs are never web-accessible.

## Alerts

```ini
LOG_ALERT_EMAIL=ops@inbitfirm.com
```

Every server error (500) emails a short alert with its error ID; identical errors are muted for 15 minutes so an outage doesn't flood your inbox.

## Background workers

| Task | Linux | Windows / XAMPP |
|---|---|---|
| Scheduler (every minute) | cron or `deploy/my-shop-scheduler.timer` | `deploy/windows-tasks.ps1` |
| Queue worker (always running) | Supervisor: `deploy/supervisor-queue.conf` | `deploy/windows-tasks.ps1` |

Failed jobs: `php console queue:failed`, `queue:retry <id|all>`, `queue:flush`.

## Payment reconciliation

`php console payments:reconcile` runs every 5 minutes from the scheduler. It:

1. retries stored webhooks that could not be processed (provider down, or the webhook arrived before our own record),
2. re-checks pending payments with the provider,
3. finishes fulfilment of paid payments whose `payment.succeeded` listeners failed or never ran,
4. closes payments still pending after 48 hours.

```bash
php console payments:fulfil        # paid payments still waiting for fulfilment, with their last error
php console payments:fulfil 42     # after fixing the cause: try payment #42 again now
```

See [Payment gateways](payments#reliability).

## Rotating APP_KEY

```bash
php console key:rotate
```

The current key moves to `APP_PREVIOUS_KEYS` and a new one is generated. Everything made with old keys keeps working (encrypted columns and cookies, signed links, hashed tokens) while new data uses the new key. Deploy the new `.env` to every server at the same time. Remove an old key once everything that depended on it has expired or been re-saved.

Rotate immediately if a key may have leaked (e.g. `.env` was committed or emailed), and also change the database and API passwords.

## Releases and rollback

`deploy/deploy.sh` does a zero-downtime release on Linux:

1. copy the code into `releases/<timestamp>/`, link the shared `.env` and `storage/`
2. `php console lint` and `security:check` (stops on HIGH problems)
3. `db:backup`, maintenance mode, `migrate`, clear caches
4. switch the `current` symlink atomically, leave maintenance mode, restart workers

Rollback: `./deploy/deploy.sh --rollback` points `current` back to the previous release. If that release had run migrations, also run `php console migrate:rollback` (write `down()` methods that really undo `up()`).

## Several application servers

Use shared stores so every server sees the same state:

```ini
SESSION_DRIVER=database
RATE_LIMIT_STORE=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Put uploads on shared storage (NFS / object storage), set `TRUSTED_PROXIES` to your load balancer, and run the scheduler on **one** server only.

## Verify a deployment

Run on the server after every deployment:

```bash
php console deploy:verify --url=https://www.example.com --restore-test=restore_test
```

| Section | Checks |
|---|---|
| Configuration | `APP_ENV=production`, `APP_DEBUG=false`, `security:check` without HIGH problems |
| Database | connection works, no pending migrations |
| Files and web server | `storage/` and `public/uploads` writable, rule files present, no secrets in `public/` |
| Background processes | the scheduler ran in the last 3 minutes, a queue worker is alive, the oldest job waits less than 15 minutes, failed jobs |
| Backups | scheduled, newest under 26 hours old, the file is complete (dump completion marker), optionally a **real restore** |
| Payments | no paid payment unfulfilled for 15+ minutes, no webhooks that gave up |
| Live site (`--url`) | `security:scan` from outside |

It exits with code 1 when anything fails, so it can gate a deployment script. The scheduler and the worker write heartbeats (`schedule:run`, `queue:work`), which is how it knows they run.

**Restore test.** `--restore-test=restore_test` restores the newest backup into a separate, empty database and compares it with the live one. Configure it with `DB_RESTORE_TEST_URL=mysql://user:pass@127.0.0.1:3306/my_shop_restore_test` — the database name must contain "restore" or "test", because it is overwritten.

In CI (no worker, scheduler or backups) use `deploy:verify --skip-heartbeats --skip-backups`.

### From outside

```bash
php console security:scan https://www.example.com
```

It requests `.env`, `.git/config`, `composer.json`, source files, `storage/` folders, backups and upload paths and fails if any of them returns content, then checks the security headers. Run it after every server change — especially on Nginx, where `.htaccess` files do nothing.

## Keeping PHP up to date

`php console security:check` warns when your PHP branch is close to or past its end of security support (PHP 8.1 ended on 31 Dec 2025; 8.2 ends on 31 Dec 2026). Always run the latest patch release of a supported branch.

**XAMPP on Windows** bundles can lag far behind (XAMPP 8.2.12 is many security releases old). `deploy/upgrade-xampp-php.ps1` replaces XAMPP's PHP with the latest official build and keeps the old one for rollback:

```powershell
# Stop Apache in the XAMPP Control Panel first
powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -DryRun     # shows what it would install
powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1             # latest PHP 8.4
powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -Branch 8.3
powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -Rollback   # back to the previous PHP
```

It verifies the download's SHA-256 against php.net's list, keeps your `php.ini` (disabling extensions the new version no longer ships, and listing them), and checks that the extensions the framework needs load. Afterwards start Apache and run `php console test`. phpMyAdmin needs version 5.2.2 or newer for PHP 8.4.
