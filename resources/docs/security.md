# Security overview

Security is built into every layer, switched on by default, and tested by the framework's own test suite. This page explains each protection and what you still need to do yourself.

> **Tip:** Run `php console security:check` at any time. It audits your configuration (debug mode, `APP_KEY`, HTTPS cookies, database account, upload folder protection...) and lists anything to fix.

## Defence in depth

| Layer | Protection |
|---|---|
| Apache (`.htaccess`) | only `public/` is served · only `index.php` can execute · secret files blocked · dangerous methods, scanners, traversal, SQL-injection and XSS signatures rejected · security headers |
| Request firewall | every request inspected before routing · IP allow/block lists · automatic bans for repeat attackers · all blocks logged |
| Input | null bytes and invalid UTF-8 removed · text trimmed · validation rules |
| Database | prepared statements only · identifier validation · multi-statement queries disabled · strict SQL mode · no mass update/delete without `where()` |
| Output | automatic escaping in Blade · Content-Security-Policy with per-request nonces |
| Sessions | HttpOnly + SameSite cookies · strict mode · server-side idle timeout · new ID on login/logout · bound to the browser and to the password |
| Auth | bcrypt (cost 12) · account lockout · no account enumeration · constant-time checks · remember-me tokens hashed |
| Tokens | 256-bit random · stored as HMAC hashes · masked CSRF tokens · signed/expiring URLs and tokens |
| Uploads | real type detection · random names · images re-encoded (metadata and hidden payloads removed) · PHP disabled in `public/uploads` |
| Payments/SMS | TLS verified · webhook signatures checked · amounts verified server-side · SMS abuse limits |
| Errors | no internals in production · secrets masked in debug pages · function arguments never logged |

## Request firewall

`Core\Firewall` runs on every request, before routing. It blocks:

- banned or block-listed IP addresses
- dangerous methods (`TRACE`, `TRACK`, `CONNECT`, ...) and oversized URLs
- control characters, null bytes and path traversal (`../`, double-encoded too)
- probes for secret files (`.env`, `.git`, `composer.json`, `backup/`, `*.sql`, `*.log`...) and other software (`wp-login.php`, `phpmyadmin`...)
- SQL injection, XSS, PHP code-injection, file-inclusion, command-injection and template-injection patterns in the URL
- known attack tools (`sqlmap`, `nikto`, `nmap`, `wpscan`...)

An IP that triggers 10 blocks is **banned for 60 minutes**. Everything is written to `storage/logs/security-*.log`.

```php
// config/firewall.php
'allow_ips' => ['196.x.x.x'],       // your office: never blocked
'block_ips' => ['203.0.113.9'],     // always blocked
'ban_after' => 10,
'ban_minutes' => 60,
'scan_request_body' => false,       // also scan form fields (may flag normal text)
```

## CSRF protection

Every POST/PUT/PATCH/DELETE in `routes/web.php` must carry the session's CSRF token (`@csrf` in forms, or the `X-CSRF-TOKEN` header for AJAX). On top of that:

- the token is **masked with a fresh one-time pad on every page**, so compression attacks (BREACH) can't read it
- requests whose `Origin`/`Referer` is another website are rejected
- the secret changes on login and logout

```js
fetch('/my-shop/posts', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/json' },
    body: JSON.stringify({ title: 'Hello' }),
});
```

## XSS protection

1. `{{ $value }}` escapes HTML. Use `{!! !!}` only for HTML you generated.
2. The **Content-Security-Policy** only allows scripts and styles from your own site, the Bootstrap CDN, or inline code carrying the request's nonce (`<script nonce="@nonce">`). Injected scripts don't run even if escaping is forgotten somewhere.
3. Validation rules `no_html` and `url` (http/https only) keep stored data clean.

Add a CDN in `config/security.php` → `csp` before loading files from it.

## SQL injection protection

```php
User::where('email', $email)->first();                       // safe: value is bound
DB::select('SELECT * FROM users WHERE id = ?', [$id]);        // safe: placeholder
User::orderBy($request->input('sort'))->get();                // throws: column names are validated
DB::select("SELECT * FROM users WHERE id = $id");             // NEVER do this
```

Column and table names are validated against a strict pattern, so user input can't change the SQL structure. MySQL multi-statement queries are disabled, so a stacked `; DROP TABLE` can never run.

## Mass assignment

Only columns listed in a model's `$fillable` can be set from arrays. A user who adds `role=admin` to a form changes nothing. Set protected columns explicitly: `$user->forceFill(['role' => 'admin'])->save()`.

## Sessions

- cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS
- 48-character session IDs, strict mode, no IDs in URLs, files stored in `storage/framework/sessions`
- sessions expire on the server after `SESSION_LIFETIME` minutes of inactivity
- a new session ID on login/logout (prevents session fixation)
- if the browser (User-Agent) changes mid-session, the user is signed out (stolen cookie)
- changing the password signs out every other device

## Rate limiting

```php
$router->post('/login', ...)->middleware('throttle:10,1');    // 10 per minute per IP / user
```

Login, registration, password reset, email verification, uploads, payments and the API are limited out of the box. Accounts additionally **lock for 15 minutes after 5 failed logins** (`config/security.php`).

## Security headers

`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Content-Security-Policy`, `Strict-Transport-Security` (HTTPS) and `Cache-Control: no-store` for signed-in pages. The `X-Powered-By` header is removed.

## Your checklist

- [ ] `APP_DEBUG=false` and `APP_ENV=production` on the live server
- [ ] HTTPS everywhere, `SESSION_SECURE_COOKIE=true`
- [ ] a dedicated database user with a strong password (never `root`)
- [ ] keep `.env` out of Git and backups that leave the server
- [ ] validate every input; authorise every action (`$this->authorize()`, `role:` middleware)
- [ ] never put user input in `{!! !!}`, `whereRaw()`, `selectRaw()` or `DB::select()` strings
- [ ] keep PHP and the framework updated; run `php console security:check`

See also: [Authentication](authentication), [Authorization](authorization), [Encryption, hashing & tokens](encryption), [File uploads](storage).

## Hardening added after the security review

| Area | Protection |
|---|---|
| Credentials | password change/reset and "sign out everywhere" revoke other sessions, remember-me tokens and API tokens ([details](authentication#when-credentials-are-revoked)) |
| Admins | two-factor authentication required, plus password re-confirmation for the admin area |
| Payments | atomic state changes (fulfilled exactly once), stored and retried webhooks, reconciliation ([details](payments#reliability)) |
| Password reset | identical answers for every address, atomic single-use tokens |
| Host header | requests for unknown hosts are rejected (`TRUSTED_HOSTS`, default: the host of `APP_URL`); production refuses to build email links without `APP_URL` |
| Proxies | `X-Forwarded-For` is read from the trusted end of the chain; `TRUSTED_PROXIES` accepts CIDR ranges |
| HTTP client | plain HTTP only to exactly `localhost` / `127.0.0.1` / `::1`, and only in development |
| API | stateless — no session or cookie; tokens only |
| Sessions | idle timeout **and** absolute lifetime (`SESSION_ABSOLUTE_LIFETIME`, default 12 h) |
| Rate limiting | fails **closed** when its storage breaks (firewall bans fail open so the site stays up); expired entries are pruned; shared database store for several servers |
| Cache | entries HMAC-signed; restored without building objects unless allow-listed |
| Queue | JSON payloads only, allow-listed job classes, no PHP `unserialize()` |
| Keys | `php console key:rotate` with `APP_PREVIOUS_KEYS` |
| Authorization | policies and `Gate` with deny-by-default ([details](authorization)) |

## Trusted hosts and proxies

```ini
APP_URL=https://www.example.com       # canonical address (required in production)
TRUSTED_HOSTS=example.com,*.example.com
TRUSTED_PROXIES=10.0.0.0/8             # only if you are behind a load balancer / Cloudflare
```

Your proxy must **append** the connecting address to `X-Forwarded-For` (Nginx: `$proxy_add_x_forwarded_for`). The framework walks the list from the right, skips your proxies and uses the first other address, so values a client puts at the start of the header are never believed. Test rate limiting through your real proxy after deploying.
