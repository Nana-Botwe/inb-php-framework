# Installation

The **INB PHP Framework** is a secure, Laravel-style PHP framework by INB IT Firm. It runs on plain PHP — no Composer or Node.js required — so it works on XAMPP, shared hosting and VPS servers alike.

## Requirements

| Requirement | Version / notes |
|---|---|
| PHP | 8.1 or newer (8.2+ recommended) |
| Database | any of: MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle, Firebird, or ODBC — see [Database](database#supported-databases) |
| PHP extensions | the PDO driver for your database (e.g. `pdo_mysql`), `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `zlib` |
| Optional | `zstd` or `brotli` extension for even better upload compression |
| Web server | Apache with `mod_rewrite` (XAMPP has it), Nginx, or the built-in dev server |

Check your server at any time with:

```bash
php console about
```

## Start a new project

1. Copy the framework folder and rename it, e.g. `htdocs/my-shop`.
2. Create your environment file and an application key:

```bash
cp .env.example .env
php console key:generate
```

3. Create an empty database (for example in phpMyAdmin) and a **dedicated database user** for it, then fill in the `DB_*` values in `.env`.
4. Create the tables and demo data:

```bash
php console migrate --seed
```

The seeder prints a randomly generated password for `admin@example.com`. Sign in with it and change it straight away.

5. Open the site.

| Where | URL |
|---|---|
| XAMPP | `http://localhost/my-shop/` (or your port, e.g. `http://localhost:3200/my-shop/`) |
| Built-in server | run `php console serve`, then open `http://localhost:8000` |

> **Tip:** On Windows/XAMPP `php` may not be on your PATH. Use the full path, e.g. `H:\xampp\php\php.exe console migrate`, or add `H:\xampp\php` to the PATH environment variable.

## Running in a sub-folder

The framework detects the folder it is served from, so links, assets and redirects work in `http://localhost/my-shop/` **and** on a real domain without changing any code. The project root contains an `.htaccess` that sends every request into `public/` — your source code, `.env`, logs and backups can never be downloaded.

## Using Composer (optional)

If you use Composer, run `composer install` (or `composer dump-autoload`). The framework then uses Composer's autoloader automatically; otherwise its built-in PSR-4 autoloader is used.

## Next steps

- Read [Configuration](configuration) to understand `.env`.
- Look at [Directory structure](structure) to know where things go.
- Run `php console security:check` before going live — see [Deployment](deployment).
