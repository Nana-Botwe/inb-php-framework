<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Core\HttpException;
use Core\Response;

/**
 * Feature detail pages linked from the welcome page: /features/{feature}
 * Content uses `code`, **bold** and [links](url) (rendered safely).
 */
final class FeatureController extends Controller
{
    public function show(string $feature): Response
    {
        $features = self::all();
        if (!isset($features[$feature])) {
            throw new HttpException(404, 'This feature page does not exist.');
        }

        return $this->view('features.show', [
            'slug' => $feature,
            'feature' => $features[$feature],
            'others' => array_diff_key($features, [$feature => true]),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'security' => self::security(),
            'payments-sms' => self::paymentsAndSms(),
            'developer-tools' => self::developerTools(),
        ];
    }

    /** @return array<string, mixed> */
    private static function security(): array
    {
        return [
            'icon' => 'shield',
            'card_title' => 'Security first',
            'card_text' => 'Request firewall, CSRF, CSP, rate limits, account lockout, encrypted tokens, signed URLs and an audit command.',
            'eyebrow' => 'Security',
            'title' => 'Security in every layer — switched on by default',
            'lead' => 'From the web server to the database, every request passes through layered protections that need no setup. Each one is covered by the framework\'s automated tests and every incident is written to a security log.',
            'stats' => [
                ['12+', 'protection layers'],
                ['0', 'lines of setup needed'],
                ['256-bit', 'keys, tokens & encryption'],
                ['24/7', 'security event logging'],
            ],
            'sections' => [
                [
                    'id' => 'firewall', 'icon' => 'shield', 'title' => 'Request firewall',
                    'text' => 'Every request is inspected **before** it reaches your routes. Attacks are stopped, logged and repeat attackers are banned automatically.',
                    'points' => [
                        'Blocks SQL injection, XSS, PHP code-injection, file-inclusion, command-injection and template-injection patterns',
                        'Stops path traversal (`../`), null bytes and double-encoded payloads',
                        'Returns 404 for probes of secret files: `.env`, `.git`, `backup/`, `*.sql`, `*.log`, `wp-login.php`, `phpmyadmin`',
                        'Rejects attack tools (sqlmap, nikto, nmap, wpscan …) and dangerous HTTP methods',
                        '**Auto-ban**: 10 blocked requests = the IP is banned for 60 minutes',
                        'IP allow-lists and block-lists in `config/firewall.php`',
                        'A second, faster filter runs in Apache (`public/.htaccess`) before PHP even starts',
                    ],
                    'code' => ['php', "// config/firewall.php\n'allow_ips' => ['196.x.x.x'],   // your office: never blocked\n'block_ips' => ['203.0.113.9'], // always blocked\n'ban_after' => 10,\n'ban_minutes' => 60,"],
                ],
                [
                    'id' => 'csrf-xss', 'icon' => 'lock', 'title' => 'CSRF & XSS protection',
                    'text' => 'Forms can\'t be forged from other websites, and injected scripts can\'t run.',
                    'points' => [
                        'CSRF token required on every POST/PUT/PATCH/DELETE (`@csrf` in forms)',
                        'Tokens are **masked with a fresh one-time pad on every page** — defeats BREACH compression attacks',
                        'Requests whose `Origin`/`Referer` is another site are rejected',
                        'Blade escapes everything in `{{ }}` automatically',
                        'Content-Security-Policy with a per-request nonce: only your scripts run',
                        'Validation rules `no_html` and `url` (http/https only) keep stored data clean',
                    ],
                    'code' => ['blade', "<form method=\"post\" action=\"{{ route('posts.store') }}\">\n    @csrf\n    <input name=\"title\" value=\"{{ old('title') }}\">   {{-- escaped --}}\n</form>\n\n<script nonce=\"@nonce\">/* allowed by the CSP */</script>"],
                ],
                [
                    'id' => 'sql', 'icon' => 'database', 'title' => 'SQL injection & data safety',
                    'text' => 'The query builder makes the safe way the easy way — on all eight supported databases.',
                    'points' => [
                        'Values are always sent as bound parameters (real prepared statements)',
                        'Table and column names are validated — user input can\'t change the SQL structure',
                        'MySQL multi-statement queries disabled: a stacked `; DROP TABLE` can never run',
                        'Strict SQL mode: bad data is rejected instead of silently truncated',
                        '`update()` and `delete()` refuse to run without a `where()`',
                        'Mass-assignment protection: only `$fillable` columns can be set from forms (no `role=admin` tricks)',
                    ],
                    'code' => ['php', "User::where('email', \$email)->first();                // safe: bound value\nDB::select('SELECT * FROM users WHERE id = ?', [\$id]);  // safe: placeholder\nUser::orderBy(\$request->input('sort'))->get();          // throws: invalid identifier"],
                ],
                [
                    'id' => 'auth', 'icon' => 'check', 'title' => 'Authentication & sessions',
                    'text' => 'A complete login system that resists brute force, session theft and account enumeration.',
                    'points' => [
                        'Passwords hashed with bcrypt (cost 12) or argon2id, upgraded automatically',
                        '**Account lockout**: 5 failed logins = 15 minutes, logged',
                        'Same message and timing whether or not the email exists',
                        'New session ID and CSRF secret on every login/logout (no session fixation)',
                        'Sessions bound to the browser — a stolen cookie used elsewhere is signed out',
                        'Changing the password signs out every other device automatically',
                        'Server-side idle timeout, HttpOnly + SameSite + Secure cookies',
                        'Remember-me cookie encrypted; only a hash is stored in the database',
                        'Email verification with signed, expiring links; roles with `role:admin` middleware',
                    ],
                ],
                [
                    'id' => 'tokens', 'icon' => 'lock', 'title' => 'Tokens & encryption',
                    'text' => 'Everything that acts like a password is unguessable, hashed at rest and expires.',
                    'table' => [
                        'head' => ['Token', 'Stored as', 'Lifetime'],
                        'rows' => [
                            ['Password reset', 'HMAC-SHA256 hash', '60 min, single use'],
                            ['API token', 'HMAC-SHA256 hash (shown once)', '90 days'],
                            ['Remember me', 'encrypted cookie + hash', '30 days, rotated on logout'],
                            ['Email verification', 'signed URL', '60 min'],
                            ['CSRF', 'session secret, masked per page', 'session'],
                        ],
                    ],
                    'code' => ['php', "\$secret = encrypt(\$data);                 // AES-256-GCM, tamper-proof\n\$code   = Token::otp(6);                   // \"493027\"\n\$stored = Token::hash(\$plain);              // keep only the hash\n\$link   = URL::temporarySignedRoute('downloads.show', 30, ['file' => 42]);"],
                ],
                [
                    'id' => 'uploads', 'icon' => 'upload', 'title' => 'Safe file uploads',
                    'text' => 'A PHP script renamed to `photo.jpg` can never be stored as runnable code.',
                    'points' => [
                        'File type detected from the real bytes, not the name or the browser',
                        'Random file names with an extension taken from the real type',
                        'Images re-encoded: hidden payloads and GPS/EXIF metadata removed',
                        'Decompression-bomb images refused (40-megapixel limit)',
                        'PHP and scripts can\'t execute in `public/uploads`',
                        'Private files kept outside the web root, served only after permission checks',
                    ],
                ],
                [
                    'id' => 'headers-errors', 'icon' => 'alert', 'title' => 'Headers, errors & logging',
                    'text' => 'Nothing leaks to visitors; everything is recorded for you.',
                    'points' => [
                        'CSP, HSTS (on HTTPS), X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP',
                        '`X-Powered-By` removed; `Cache-Control: no-store` for signed-in pages',
                        'Production errors show a friendly page and an error reference only',
                        'Passwords, tokens and cookies masked on debug pages; function arguments never logged',
                        'Security log: failed logins, lockouts, CSRF failures, firewall blocks, bans, forbidden pages, role changes',
                    ],
                ],
                [
                    'id' => 'audit', 'icon' => 'terminal', 'title' => 'One-command security audit',
                    'text' => 'Check your configuration before every launch.',
                    'code' => ['text', "\$ php console security:check\n  ✓ APP_KEY is set (256-bit)\n  ✓ Debug mode is off in production\n  ✓ Request firewall is enabled\n  ✓ The app does not use a database super-user\n  ✓ PHP execution is blocked in public/uploads\n  ✗ [HIGH] Set SESSION_SECURE_COOKIE=true on the live (HTTPS) site"],
                ],
            ],
            'docs' => ['security' => 'Security overview', 'authentication' => 'Authentication', 'authorization' => 'Authorization & roles', 'encryption' => 'Encryption & tokens', 'storage' => 'File uploads'],
        ];
    }

    /** @return array<string, mixed> */
    private static function paymentsAndSms(): array
    {
        return [
            'icon' => 'card',
            'card_title' => 'Payments & SMS',
            'card_text' => 'Paystack, Flutterwave, Stripe, PayPal, Hubtel · Twilio, Africa\'s Talking, Hubtel, Arkesel — install with one command.',
            'eyebrow' => 'Payments & SMS',
            'title' => 'Take payments and send SMS in minutes',
            'lead' => 'Five payment gateways and four SMS providers share one simple API. Pick one with a single command, add your keys to `.env`, and the secure flow — verification, webhooks, amount checks, abuse limits — is already built.',
            'stats' => [
                ['5', 'payment gateways'],
                ['4', 'SMS gateways'],
                ['1', 'command to install each'],
                ['0', 'card details on your server'],
            ],
            'sections' => [
                [
                    'id' => 'gateways', 'icon' => 'card', 'title' => 'Payment gateways',
                    'text' => 'Customers pay on the provider\'s secure hosted page, so card details never touch your server.',
                    'table' => [
                        'head' => ['Gateway', 'Where', 'Methods', 'Install'],
                        'rows' => [
                            ['Paystack', 'Ghana, Nigeria, Kenya, South Africa', 'Cards, mobile money, bank', '`php console payment:install paystack`'],
                            ['Flutterwave', 'Across Africa', 'Cards, mobile money, bank, USSD', '`php console payment:install flutterwave`'],
                            ['Stripe', 'Global', 'Cards, Apple Pay, Google Pay', '`php console payment:install stripe`'],
                            ['PayPal', 'Global', 'PayPal balance, cards', '`php console payment:install paypal`'],
                            ['Hubtel', 'Ghana', 'MTN MoMo, Telecel Cash, AirtelTigo Money, cards', '`php console payment:install hubtel`'],
                        ],
                    ],
                ],
                [
                    'id' => 'flow', 'icon' => 'check', 'title' => 'A secure payment flow, ready to use',
                    'text' => 'Signed-in users can already pay at `/payments`. Behind it:',
                    'steps' => [
                        '**Checkout** — a pending payment is saved (amount in pesewas/kobo/cents, never floats) and the customer is sent to the provider.',
                        '**Callback** — when they return, the payment is **verified with the provider\'s API**; the redirect alone is never trusted.',
                        '**Webhook** — the provider\'s server-to-server call is accepted only with a valid **signature**, then verified again.',
                        '**Amount check** — marked paid only if the amount and currency **exactly match**. Underpayments are flagged and logged.',
                        '**Idempotent** — callback and webhook may both arrive; the order is only completed once.',
                    ],
                    'code' => ['php', "\$checkout = Payment::initialize(\n    PaymentRequest::make('150.00', 'GHS', \$user->email)\n        ->description('Course fee')\n        ->callbackUrl(route('payments.callback', ['gateway' => 'paystack'], true))\n);\nreturn redirect(\$checkout->url);\n\n// later\n\$result = Payment::verify(\$order->gateway_reference);\nif (\$result->isSuccessful() && \$result->matches(\$order->amount_minor, 'GHS')) {\n    // deliver the order\n}"],
                ],
                [
                    'id' => 'webhooks', 'icon' => 'shield', 'title' => 'Webhook verification',
                    'text' => 'Forged webhooks get HTTP 400 and a security-log entry. Comparisons are constant-time.',
                    'table' => [
                        'head' => ['Gateway', 'How it is verified'],
                        'rows' => [
                            ['Paystack', 'HMAC-SHA512 of the body with your secret key'],
                            ['Flutterwave', 'Your secret hash in the `verif-hash` header'],
                            ['Stripe', 'HMAC-SHA256 signature, rejected after 5 minutes (replay protection)'],
                            ['PayPal', 'PayPal\'s own verify-webhook-signature API'],
                            ['Hubtel', 'Confirmed through Hubtel\'s status-check API'],
                        ],
                    ],
                ],
                [
                    'id' => 'sms', 'icon' => 'message', 'title' => 'SMS gateways',
                    'text' => 'Send OTPs, alerts and reminders with one line. Local numbers are converted automatically (`0241234567` → `+233241234567`).',
                    'table' => [
                        'head' => ['Gateway', 'Coverage', 'Install'],
                        'rows' => [
                            ['Twilio', 'Global', '`php console sms:install twilio`'],
                            ['Africa\'s Talking', 'Ghana, Kenya, Nigeria, Uganda … (free sandbox)', '`php console sms:install africastalking`'],
                            ['Hubtel', 'Ghana', '`php console sms:install hubtel`'],
                            ['Arkesel', 'Ghana', '`php console sms:install arkesel`'],
                        ],
                    ],
                    'code' => ['php', "Sms::send('0241234567', 'Your code is ' . Token::otp(6));\nSms::send(['0241234567', '+233201234567'], 'Classes resume on Monday.');\nSms::send('+14155550100', 'Hi', gateway: 'twilio');"],
                ],
                [
                    'id' => 'abuse', 'icon' => 'alert', 'title' => 'Built-in abuse protection',
                    'points' => [
                        'Each phone number receives at most 10 SMS per hour — stops costly "SMS pumping" fraud',
                        'Messages limited to 918 characters (6 parts); numbers validated',
                        'Payment and checkout routes rate-limited',
                        'TLS certificates always verified; credentials refused over plain HTTP',
                        'Secret keys stay in `.env` — never in JavaScript or views',
                    ],
                ],
                [
                    'id' => 'testing', 'icon' => 'terminal', 'title' => 'Develop without spending money',
                    'text' => 'Use test keys, the `log` SMS driver (messages go to `storage/logs/sms.log`), and fake responses in tests.',
                    'code' => ['bash', "php console payment:list        # which gateways have credentials\nphp console sms:test 0241234567 # send a test SMS\nphp console test IntegrationsTest"],
                ],
            ],
            'docs' => ['payments' => 'Payment gateways', 'sms' => 'SMS gateways', 'queues' => 'Queues', 'testing' => 'Testing'],
        ];
    }

    /** @return array<string, mixed> */
    private static function developerTools(): array
    {
        return [
            'icon' => 'terminal',
            'card_title' => 'Developer tools',
            'card_text' => 'Generators, migrations for 8 databases, seeders, queues, scheduler, tests, helpful error pages and maintenance mode.',
            'eyebrow' => 'Developer tools',
            'title' => 'Everything you need to build — and nothing to install',
            'lead' => 'A Laravel-style console, code generators, migrations that run on every major database, a built-in test runner and error pages that tell you how to fix the problem. No Composer or Node.js required.',
            'stats' => [
                ['45+', 'console commands'],
                ['8', 'database drivers'],
                ['35', 'documentation guides'],
                ['1', 'command to run all tests'],
            ],
            'sections' => [
                [
                    'id' => 'console', 'icon' => 'terminal', 'title' => 'The console',
                    'text' => 'Run `php console list` from your project folder.',
                    'table' => [
                        'head' => ['Command', 'What it does'],
                        'rows' => [
                            ['`make:controller PostController --resource`', 'controller with all 7 CRUD actions'],
                            ['`make:model Post -m -f -s`', 'model + migration + factory + seeder'],
                            ['`make:migration` / `make:mail` / `make:job` / `make:middleware` / `make:command` / `make:test`', 'generate the file you need'],
                            ['`route:list`', 'every URL with its name, action and middleware'],
                            ['`about` · `db:drivers` · `db:test`', 'environment, installed database drivers, connection test'],
                            ['`down --secret=xyz` · `up`', 'maintenance mode with a private bypass link'],
                            ['`cache:clear` · `view:clear` · `optimize:clear`', 'clear caches'],
                        ],
                    ],
                ],
                [
                    'id' => 'databases', 'icon' => 'database', 'title' => 'Every major database',
                    'text' => 'The same models, queries and migrations work on all of them — each database gets its own SQL dialect automatically.',
                    'table' => [
                        'head' => ['Database', 'DB_CONNECTION', 'Notes'],
                        'rows' => [
                            ['MySQL 5.7+', '`mysql`', 'strict mode, utf8mb4'],
                            ['MariaDB 10.3+', '`mariadb`', 'same as MySQL'],
                            ['PostgreSQL 10+', '`pgsql`', 'RETURNING ids, ILIKE search, schemas, SSL modes'],
                            ['SQLite 3.35+', '`sqlite`', 'zero setup — great for tests and small apps'],
                            ['SQL Server 2016+ / Azure SQL', '`sqlsrv`', 'OFFSET/FETCH, OUTPUT INSERTED ids'],
                            ['Oracle 12c+', '`oracle`', 'identity columns, RETURNING INTO'],
                            ['Firebird 3+', '`firebird`', 'standard SQL grammar'],
                            ['Anything with ODBC', '`odbc`', 'IBM Db2, Access, Informix …'],
                        ],
                    ],
                    'code' => ['php', "// .env: DB_CONNECTION=pgsql   (or a URL: DB_URL=postgres://user:pass@host:5432/app)\n\nDB::connection('reporting')->table('sales')->sum('total'); // a second database\n\nfinal class Invoice extends Model\n{\n    protected static ?string \$connection = 'legacy';          // per-model connection\n}"],
                ],
                [
                    'id' => 'migrations', 'icon' => 'database', 'title' => 'Migrations, seeders & factories',
                    'points' => [
                        'Schema builder with 30+ column types, indexes, foreign keys and soft deletes',
                        '`migrate`, `migrate:rollback --step=N`, `migrate:fresh --seed`, `migrate:status`',
                        'Destructive commands refuse to run in production without `--force`',
                        'Factories with a built-in fake-data generator (Ghana-friendly names, numbers and cities)',
                        'Seeder creates an admin with a **random** password — no default password to guess',
                    ],
                    'code' => ['php', "Schema::create('posts', function (Blueprint \$table) {\n    \$table->id();\n    \$table->foreignId('user_id')->constrained()->cascadeOnDelete();\n    \$table->string('title');\n    \$table->boolean('published')->default(false);\n    \$table->timestamps();\n});"],
                ],
                [
                    'id' => 'testing', 'icon' => 'check', 'title' => 'Testing built in',
                    'text' => 'No PHPUnit needed. Each test gets a fresh in-memory database; emails and SMS are captured; HTTP calls can be faked.',
                    'code' => ['php', "public function test_customers_can_order(): void\n{\n    \$this->actingAs(\$this->createUser())\n        ->post('/orders', ['product_id' => 1, 'qty' => 2])\n        ->assertRedirect('/orders');\n\n    \$this->assertDatabaseHas('orders', ['qty' => 2]);\n}"],
                ],
                [
                    'id' => 'errors', 'icon' => 'alert', 'title' => 'Error pages that help',
                    'points' => [
                        'Debug page shows the message, the source code around the error and every stack frame',
                        '**Suggested fixes** for common problems: database down, missing table, missing view, unknown route, missing `APP_KEY` …',
                        'Request details with passwords and tokens masked',
                        'Production shows a friendly styled page and an error reference that matches the log',
                        'Coloured console errors with the same suggested fix',
                    ],
                ],
                [
                    'id' => 'background', 'icon' => 'clock', 'title' => 'Queues & scheduler',
                    'points' => [
                        '`dispatch(new SendInvoice($id))` — run now (sync) or in the background (database queue)',
                        'Retries, back-off, failed-job list and `queue:retry`',
                        'Schedule tasks in `routes/console.php`: `->daily()`, `->weeklyOn(1, \'08:00\')`, `->cron(\'…\')`',
                        'One cron entry / Windows scheduled task runs everything',
                    ],
                ],
                [
                    'id' => 'more', 'icon' => 'terminal', 'title' => 'And everything else',
                    'points' => [
                        'Blade templates with layouts, components, stacks and `$loop`',
                        'ORM with relationships, eager loading, casts (including `encrypted` and `hashed`), scopes and events',
                        'Service container with autowiring, service providers, events, cache and localization',
                        'Three email templates (`php console mail:theme modern|classic|minimal`)',
                        'Collections, pagination, string/array helpers and an HTTP client',
                        'Light and dark themes with a toggle on every page',
                    ],
                ],
            ],
            'docs' => ['console' => 'Console commands', 'database' => 'Database', 'migrations' => 'Migrations', 'testing' => 'Testing', 'errors' => 'Error handling'],
        ];
    }
}
