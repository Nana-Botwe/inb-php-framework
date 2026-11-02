# INB PHP Framework

A secure, Laravel-style PHP framework by **INB IT Firm**. No Composer or Node.js required.

**Full documentation (35 guides) is built in:** open `/docs` in your browser, e.g. `http://localhost/MVC%20Models/docs`.
The Markdown sources are in `resources/docs/`.

## Quick start

```bash
cp .env.example .env              # then set DB_* values
php console key:generate
php console migrate --seed        # prints a random admin password
php console serve                 # or open the folder through XAMPP/Apache
```

## What's included

- Routing (groups, names, resources, model binding), middleware, controllers and a service container
- Blade templates, layouts, components and the `$loop` variable
- Query builder, ORM (relationships, casts, soft deletes, scopes, events), migrations, seeders and factories
- Authentication: register, login with lockout, remember me, password reset, email verification, profile, roles/admin and API tokens
- Security: request firewall with auto-ban, masked CSRF tokens, CSP with nonces, security headers, rate limiting, session hijack protection, AES-256-GCM encryption, HMAC-hashed tokens, signed URLs, `security:check` audit
- Uploads: real-type validation, automatic compression (zstd, then Brotli, then gzip), and WebP image optimisation with metadata stripping
- Mail (SMTP/log) with 3 templates: `php console mail:theme modern|classic|minimal`
- 5 payment gateways: `php console payment:install paystack|flutterwave|stripe|paypal|hubtel`
- 4 SMS gateways: `php console sms:install twilio|africastalking|hubtel|arkesel`
- Queues, scheduler, cache, events, localization, collections and pagination
- Styled error pages with error IDs, and a debug page with suggested fixes
- Console generators, a test runner (`php console test`) and maintenance mode

## Requirements

PHP 8.1+ with pdo, mbstring, openssl, curl, fileinfo, gd and zlib. MySQL/MariaDB or SQLite. Apache with mod_rewrite (or Nginx).

## Security notes

- Only `public/` is web-accessible; the root `.htaccess` routes everything there.
- Set `APP_DEBUG=false`, `APP_ENV=production` and `SESSION_SECURE_COOKIE=true` on live servers, then run `php console security:check`.
- Never commit `.env`.

## Contributing

All improvements happen on the [`community`](../../tree/community) branch; `main` is locked. Share ideas in Discussions, or read [CONTRIBUTING.md](CONTRIBUTING.md) to send a pull request.
