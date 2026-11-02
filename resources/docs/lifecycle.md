# Request lifecycle

Knowing the order things happen in makes debugging easy.

1. **Apache** (`.htaccess`) — rewrites the URL into `public/`, blocks dangerous methods, attack signatures, scanners and secret files, and adds security headers.
2. **`public/index.php`** — loads `bootstrap/app.php`, which sets up autoloading and creates the `Core\App`.
3. **Boot** — `.env` and `config/*.php` are loaded, error handling is registered, service providers run `register()` then `boot()`, and the route files are loaded.
4. **Request** — `Core\Request` is built from the superglobals. Input is cleaned on the way in: null bytes and invalid UTF-8 are removed and text is trimmed (passwords are not trimmed).
5. **Session** — started with secure cookie settings; idle sessions expire on the server.
6. **Firewall** — `Core\Firewall` inspects the request (IP bans, traversal, SQL injection/XSS/code-injection patterns, scanner user agents). Blocked requests stop here and are logged.
7. **Maintenance mode** — if the app is down, a 503 page is returned (unless you have the bypass cookie).
8. **Routing** — the URL and method are matched against your routes. No match: 404 (or 405 for the wrong method).
9. **Middleware** — global middleware, then the route's group (`web` or `api`), then route middleware (`auth`, `verified`, `role:admin`, `throttle:5,1`...).
10. **Controller** — dependencies, route parameters and models are injected; your action runs and returns a response.
11. **Response** — strings become HTML, arrays/models become JSON. Security headers, CSP and cookies are added, and the response is sent.
12. **Errors** — any exception along the way becomes the right response: validation errors redirect back, HTTP errors show the styled error page, anything else is logged with an error ID.

```php
// public/index.php (simplified)
$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
```
