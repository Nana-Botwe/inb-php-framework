# Deployment

## Checklist

1. **Environment:** start from `.env.production.example` (never copy your development `.env`):

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.your-domain.com      # required: email links are built from it
TRUSTED_HOSTS=your-domain.com,www.your-domain.com
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
QUEUE_CONNECTION=database
LOG_ALERT_EMAIL=ops@your-domain.com
BACKUP_SCHEDULE=true
```

2. **Key:** run `php console key:generate` once on the server (keep the same key afterwards).
3. **Database:** a dedicated user with a long random password and rights on this database only. Then `php console migrate`.
4. **Document root:** point the domain at the `public/` folder. If your host doesn't allow that, upload the whole project — the root `.htaccess` routes everything into `public/` and blocks the rest.
5. **Permissions:** `storage/` and `public/uploads/` must be writable by the web server. Nothing else needs to be.
6. **HTTPS:** install a certificate (Let's Encrypt / cPanel AutoSSL). HSTS is sent automatically over HTTPS.
7. **Scheduler:** add the cron entry from [Task scheduling](scheduling); run a [queue worker](queues) if `QUEUE_CONNECTION=database`.
8. **Verify:** run `php console deploy:verify --url=https://your-domain.com` (configuration, migrations, worker, scheduler, backups, live-site scan) and fix every failure. Add `--restore-test=restore_test` to prove the backup restores — see [Operations](operations#verify-a-deployment). Make sure an administrator has set up two-factor authentication.
9. **Clear caches:** `php console optimize:clear`.

## PHP settings (php.ini)

```ini
expose_php = Off
display_errors = Off
log_errors = On
upload_max_filesize = 10M
post_max_size = 12M
session.cookie_httponly = 1
opcache.enable = 1
```

## Apache

`mod_rewrite` and `mod_headers` must be enabled with `AllowOverride All` for the project folder. The shipped `.htaccess` files already:

- route every request through `public/index.php` and allow no other PHP file to run
- block hidden files, backups, logs, SQL dumps and config files
- reject dangerous methods, attack tools, traversal, SQL-injection/XSS signatures and probes for WordPress/phpMyAdmin
- disable script execution in `public/uploads`
- add security headers, gzip compression and browser caching for assets

A complete virtual host is in `deploy/apache-vhost.conf`. After setting it up, check that `https://your-domain.com/.env` and `https://your-domain.com/composer.json` return 403/404.

## Nginx

`deploy/nginx.conf` is a complete, production-ready file (TLS, the same blocks as the `.htaccess` rules, upload protection, headers). The essentials:

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/my-shop/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ \.php$ { return 404; }                     # no other PHP files
    location ~ /\.(?!well-known) { deny all; }            # hidden files
    location ^~ /uploads/ { location ~ \.(php|phtml|phar)$ { deny all; } }
}
```

## Behind Cloudflare or a load balancer

Set `TRUSTED_PROXIES` (IPs or CIDR ranges, e.g. `10.0.0.0/8` or Cloudflare's published ranges) so the real client IP is used for rate limits and bans. The proxy must **append** to `X-Forwarded-For`, not replace it. Only addresses you list are skipped, so a client can't fake its IP by sending its own header. With several servers, also set `SESSION_DRIVER=database`, `CACHE_STORE=database` and `RATE_LIMIT_STORE=database`.

## Maintenance mode

```bash
php console down --secret=my-bypass-code --message="Upgrading, back in 10 minutes"
# visit https://your-domain.com/my-bypass-code once to see the live site yourself
php console up
```

## Updating

1. `php console down --secret=...`
2. Upload the new code (or `git pull`).
3. `php console migrate --force`
4. `php console optimize:clear`
5. `php console up`

On Linux, `deploy/deploy.sh` does this with release folders and a `current` symlink, and `deploy/deploy.sh --rollback` switches back to the previous release in seconds. Keep the worker and scheduler running with `deploy/supervisor-queue.conf` and `deploy/my-shop-scheduler.timer` (Windows: `deploy/windows-tasks.ps1`).

## Backups

`php console db:backup` (scheduled daily when `BACKUP_SCHEDULE=true`), and test restores regularly with `db:restore`. See [Operations & maintenance](operations) for backups, log retention, alerts, payment reconciliation and key rotation. Keep `.env` and `APP_KEY` in a password manager.

## Hiding the docs

This documentation is available at `/docs`. On a public production site you can turn it off:

```ini
DOCS_ENABLED=false
```
