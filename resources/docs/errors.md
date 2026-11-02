# Error handling & logging

Every error, from a typo in a view to a database outage, is caught, logged and shown in the right way.

## In development (APP_DEBUG=true)

You get a styled debug page with:

- the exception type and message
- a **suggested fix** for common problems (database not running, missing table, missing view, unknown route, `APP_KEY` missing, undefined variable, class not found...)
- the **source code** around the line that failed
- the full stack trace, with your own code highlighted and framework frames dimmed, each with a code preview
- the request (method, URL, headers, input) with passwords, tokens and cookies **masked**
- the environment (PHP, framework version, database driver, memory)
- the error ID that matches the log entry

## In production (APP_DEBUG=false)

Visitors see a friendly, styled page for each status (404, 403, 419, 429, 500, 503...) — never code, SQL or file paths. Server errors show an **error reference** such as `ERR-7F3A9C21`. Search for it in `storage/logs/app-YYYY-MM-DD.log` to find the full details.

API requests receive JSON:

```json
{ "message": "Server Error", "status": 500, "error_id": "ERR-7F3A9C21" }
```

## Throwing HTTP errors

```php
abort(404);
abort(403, 'You can only edit your own posts.');   // the message is shown on 4xx pages
throw new Core\HttpException(429, 'Slow down', ['Retry-After' => '60']);
```

## Exceptions with a fix

Implement `Core\Exceptions\HasSolution` and the debug page shows your advice:

```php
final class InvoiceLockedException extends RuntimeException implements Core\Exceptions\HasSolution
{
    public function solution(): string
    {
        return 'Unlock the invoice in Settings → Billing before editing it.';
    }
}
```

## Customising error pages

All pages use `resources/views/errors/error.blade.php`. To give a status its own design, create `resources/views/errors/404.blade.php` (variables: `$status`, `$message`, `$errorId`).

## Reporting without stopping

```php
try {
    $sms->send(...);
} catch (Throwable $e) {
    $id = report($e);           // logged with an ID, the request continues
}
```

## Logging

| File | Contents |
|---|---|
| `storage/logs/app-YYYY-MM-DD.log` | errors with ID, request, user and stack trace; app events |
| `storage/logs/security-YYYY-MM-DD.log` | failed logins, lockouts, CSRF failures, firewall blocks, bans, forbidden pages, role changes, invalid signatures, webhook rejections |
| `storage/logs/mail.log` | emails when `MAIL_MAILER=log` |
| `storage/logs/sms.log` | SMS when `SMS_GATEWAY=log` |

```php
logger('order_placed', ['order' => $order->id]);            // app log (JSON line)
Core\Log::security('suspicious_export', ['rows' => 5000]);   // security log
```

> **Security:** Never log passwords, card numbers, tokens or full ID numbers. Stack traces never include function arguments (`zend.exception_ignore_args` is on), so passwords passed to functions can't leak into logs.

## PHP warnings

Warnings and notices (e.g. "Undefined array key") are turned into exceptions, so bugs surface immediately instead of producing wrong data. Deprecation notices are only logged.
