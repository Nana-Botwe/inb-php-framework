# Configuration

All settings live in two places:

- **`.env`** — values that change per server (database password, API keys, debug mode). Never commit it.
- **`config/*.php`** — the structure of each setting, reading values from `.env` with `env()`.

## The .env file

```ini
APP_NAME="INB PHP Framework"
APP_ENV=local          # local | staging | production
APP_DEBUG=true         # MUST be false on a live site
APP_URL=http://localhost:3200/my-shop
APP_KEY=base64:...     # php console key:generate

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_DATABASE=my_shop
DB_USERNAME=my_shop_user
DB_PASSWORD=a-long-random-password
```

Values can be quoted (`"My App"`). `true`, `false`, `null` and `empty` are converted automatically. Real server environment variables override `.env`.

> **Security:** `.env` holds your secrets. It is blocked from the web by `.htaccess` and listed in `.gitignore`. Never email it, paste it in chats, or put it inside `public/`.

## Reading configuration

```php
config('app.name');                 // "INB PHP Framework"
config('mail.from.address');        // dot notation reaches into arrays
config('payments.default', 'none'); // default when missing
env('APP_DEBUG');                   // read .env directly (prefer config() in your code)
```

Change a value at runtime (only for the current request):

```php
Core\Config::set('app.locale', 'fr');
```

## Configuration files

| File | What it controls |
|---|---|
| `config/app.php` | name, environment, debug, URL, timezone, locale, key, service providers |
| `config/auth.php` | user model, login/home routes, remember-me, email verification, reset links, two-factor, password confirmation, token revocation |
| `config/database.php` | database connection |
| `config/session.php` | session driver (file/database), idle and absolute lifetime, HTTPS-only cookie |
| `config/security.php` | security headers, Content-Security-Policy, login lockout, trusted proxies, rate-limit store |
| `config/firewall.php` | request firewall rules, IP allow/block lists, auto-ban |
| `config/middleware.php` | middleware groups and aliases, CSRF exceptions |
| `config/mail.php` | mail driver, email template, sender, SMTP |
| `config/payments.php` | payment gateways and credentials |
| `config/sms.php` | SMS gateways, country code, anti-abuse limits |
| `config/uploads.php` | upload limits, compression, image optimisation |
| `config/filesystems.php` | storage disks (private / public) |
| `config/queue.php` | queue driver, allowed job classes |
| `config/cache.php` | cache store, classes allowed in cached values |
| `config/http.php` | outgoing HTTP client (timeouts, local HTTP in development) |
| `config/logging.php` | log retention, error alert email |
| `config/backup.php` | database backups (tool paths, schedule, how many to keep) |
| `config/cors.php` | which front-end domains may call your API |
| `config/hashing.php` | password hashing algorithm and cost |
| `config/docs.php` | this documentation site |

## Environments

`APP_ENV` lets your code behave differently per server:

```php
if (app()->environment('production')) {
    // live-only behaviour
}
```

Blade has `@env('local') ... @endenv` and `@production ... @endproduction`.

## Debug mode

With `APP_DEBUG=true` errors show a detailed page with the code, stack trace and a suggested fix. With `APP_DEBUG=false` visitors see a friendly page with an error reference only. See [Error handling](errors).
