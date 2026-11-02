# Directory structure

```text
my-project/
├── app/                    Your application code
│   ├── Console/Commands/   Custom console commands
│   ├── Http/
│   │   ├── Controllers/    Controllers (Auth/, Admin/, Api/ ...)
│   │   └── Middleware/     Your middleware (Authenticate, RequireRole ...)
│   ├── Jobs/               Queued background jobs
│   ├── Mail/               Email classes (Mailables)
│   ├── Models/             Database models (User, Payment ...)
│   └── Providers/          Service providers (AppServiceProvider)
├── bootstrap/app.php       Autoloading + creates the application
├── config/                 Configuration files
├── core/                   The framework itself (you normally don't edit it)
│   └── stubs/              Templates used by the make:* commands and mail themes
├── database/
│   ├── factories/          Model factories (fake data)
│   ├── migrations/         Database migrations
│   └── seeders/            Seeders
├── lang/en/                Translations
├── public/                 THE ONLY WEB-ACCESSIBLE FOLDER
│   ├── index.php           Front controller — every request enters here
│   ├── assets/             Your CSS, JS, images
│   ├── uploads/            Public uploads (PHP execution disabled)
│   ├── .htaccess           Request filter + security headers
│   └── robots.txt
├── resources/
│   ├── docs/               This documentation (Markdown)
│   └── views/              Blade templates
├── routes/
│   ├── web.php             Website routes (sessions + CSRF)
│   ├── api.php             API routes (/api prefix, tokens, CORS)
│   └── console.php         Closure commands + task schedule
├── storage/                Logs, cache, sessions, compiled views, private files
├── tests/                  Feature and unit tests
├── .env                    Your secrets (never commit)
├── .htaccess               Sends everything into public/
└── console                 Command-line tool:  php console list
```

## Where should my code go?

| I want to... | Put it in |
|---|---|
| Add a page/URL | a route in `routes/web.php` + a controller method + a view |
| Talk to a table | a model in `app/Models` (`php console make:model Post -m`) |
| Change the database | a migration (`php console make:migration`) |
| Check something before a request | middleware (`php console make:middleware`) |
| Send an email | a Mailable in `app/Mail` (`php console make:mail`) |
| Do slow work in the background | a Job in `app/Jobs` (`php console make:job`) |
| Register shared services | `app/Providers/AppServiceProvider.php` |
| Add CSS/JS | `public/assets/` and link with `asset('assets/css/app.css')` |

> **Security:** Only `public/` is reachable from a browser. Never put configuration, backups or uploads you don't want public anywhere inside `public/`.
