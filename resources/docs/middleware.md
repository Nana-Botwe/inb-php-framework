# Middleware

Middleware runs before (and after) your controller. Use it for checks that apply to many routes: "must be signed in", "must be admin", rate limits...

## Built-in middleware

| Alias | What it does |
|---|---|
| `auth` | Only signed-in users. Guests are sent to the login page (and back afterwards). |
| `auth:api` | Only requests with a valid API token (`Authorization: Bearer ...`). |
| `guest` | Only guests (signed-in users are sent to the dashboard). |
| `verified` | User must have confirmed their email address. |
| `role:admin` / `role:admin,editor` | User must have one of the roles, otherwise 403 (logged). |
| `signed` | URL must have a valid, unexpired signature (`URL::signedRoute`). |
| `throttle:5,1` | Max 5 requests per 1 minute per user/IP, otherwise 429. |
| `cors` | Adds CORS headers (already in the `api` group). |

Groups (applied automatically):

```php
// config/middleware.php
'groups' => [
    'web' => [Core\Middleware\VerifyCsrfToken::class],
    'api' => [Core\Middleware\HandleCors::class, 'throttle:60,1'],
],
```

## Writing middleware

```bash
php console make:middleware EnsureUserIsActive
```

```php
namespace App\Http\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Middleware\Middleware;
use Core\Request;

final class EnsureUserIsActive implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (Auth::user()?->is_active === 0) {
            throw new HttpException(403, 'Your account is suspended.');
        }

        $response = $next($request);          // run the rest of the app
        $response->header('X-Checked', 'yes'); // you can change the response afterwards

        return $response;
    }
}
```

Register an alias, then use it on routes:

```php
// config/middleware.php
'aliases' => [
    'active' => App\Http\Middleware\EnsureUserIsActive::class,
],

$router->get('/dashboard', ...)->middleware('auth', 'active');
```

### Parameters

`->middleware('plan:gold,platinum')` calls `handle($request, $next, 'gold', 'platinum')`.

### Global middleware

Put a class in `'global'` in `config/middleware.php` to run it on every route.

## CSRF exceptions

Webhooks from other servers can't send your CSRF token. List them in `csrf_except` and verify them another way (signatures):

```php
'csrf_except' => ['payments/webhook/*'],
```

> **Warning:** Never add normal forms to `csrf_except`.
