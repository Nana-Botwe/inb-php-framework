# Building APIs

Routes in `routes/api.php` are prefixed with `/api`, return JSON, have CORS enabled, are limited to 60 requests per minute, and don't use sessions or CSRF. They authenticate with **API tokens** (like Laravel Sanctum).

## Included endpoints

| Method | URL | Auth | Purpose |
|---|---|---|---|
| GET | `/api/health` | — | health check |
| POST | `/api/tokens` | email + password | get a token (5 attempts/min, lockout applies) |
| GET | `/api/user` | Bearer token | the token's user |
| DELETE | `/api/tokens/current` | Bearer token | revoke the token being used |

```bash
curl -X POST http://localhost:3200/my-shop/api/tokens \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"ama@example.com","password":"Secret#123","device_name":"android"}'
# {"token":"12|kQ3...","expires_at":"2027-01-17T10:00:00+00:00"}

curl http://localhost:3200/my-shop/api/user -H "Authorization: Bearer 12|kQ3..."
```

## Tokens

```php
$token = $user->createToken('android-app');                               // all abilities
$token = $user->createToken('reports', ['reports:read'], new DateTimeImmutable('+30 days'));
$token->plainTextToken;          // show ONCE — only an HMAC hash is stored

$user->tokens();                 // Collection
$user->revokeTokens();           // sign out every device
$request->user()->currentAccessToken();
$request->user()->tokenCan('reports:read');
```

Protect routes:

```php
$router->group(['middleware' => ['auth:api']], function (Router $router) {
    $router->apiResource('orders', OrderApiController::class);
});
```

Invalid or expired tokens get `401` with a `WWW-Authenticate: Bearer` header. Expired tokens are cleaned up daily by the scheduler.

## Responses

```php
return $this->json(['data' => $order], 201);
return Order::where('user_id', Auth::id())->paginate(20);   // JSON with pagination info
return $order;                                              // hidden fields removed
```

Validation errors become `422` JSON automatically:

```json
{ "message": "The given data was invalid.", "errors": { "amount": ["The amount field is required."] } }
```

Other errors: `{ "message": "...", "status": 404 }` (plus `error_id` for 500s; debug mode adds `exception`, `file` and `fix`).

## CORS

Allow your front-end domains in `.env`:

```ini
CORS_ALLOWED_ORIGINS=https://app.inbitfirm.com,https://admin.inbitfirm.com
```

Pre-flight `OPTIONS` requests are answered automatically. Avoid `*` in production.

## Calling the API from your own pages

Same-site JavaScript can call **web** routes with the session cookie and CSRF header (see [Security](security#csrf-protection)). Use `/api` with tokens for mobile apps and other servers.
