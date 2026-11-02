# Requests

`Core\Request` gives you safe access to everything the browser sent. Type-hint it in a controller method, or call `request()` anywhere.

> **Security:** Never use `$_GET`, `$_POST` or `$_SERVER` directly. The Request object has already removed null bytes and invalid UTF-8, trimmed text, and blocked attack patterns through the firewall.

## Input

```php
$request->input('name');               // body first, then query string
$request->input('name', 'Guest');      // with a default
$request->query('page');               // query string only
$request->string('q');                 // always a string
$request->integer('page', 1);          // always an int
$request->boolean('remember');         // "1", "on", "true", "yes" -> true
$request->all();                       // everything
$request->only(['name', 'email']);
$request->except(['password']);
$request->has('email');
$request->filled('email');             // present and not empty
$request->missing('email');
```

JSON request bodies (`Content-Type: application/json`) are decoded automatically — the same methods work.

> **Warning:** `all()` returns everything the user sent, including fields you didn't expect (like `role=admin`). Save **validated** data only: `$data = $request->validate([...])`.

## Files

```php
$file = $request->file('avatar');      // Core\Http\UploadedFile or null
$request->hasFile('avatar');
$path = $file->store('avatars', 'public');
```

See [File uploads & compression](storage).

## Request information

```php
$request->method();            // GET, POST, PUT (honours the hidden _method field)
$request->path();              // "/posts/5" (without the sub-folder)
$request->pathWithQuery();     // "/posts?page=2"
$request->is('admin/*');
$request->routeIs('admin.*');
$request->ip();                // real client IP (proxies only if trusted)
$request->userAgent();
$request->header('Accept');
$request->bearerToken();       // from "Authorization: Bearer ..."
$request->isSecure();          // HTTPS?
$request->wantsJson();         // AJAX / Accept: application/json / /api/*
$request->user();              // the signed-in user
$request->cookie('name');
$request->param('id');         // a route parameter
```

## Behind a proxy or Cloudflare

`X-Forwarded-For` and `X-Forwarded-Proto` are only trusted from proxies you list, otherwise anyone could fake their IP to dodge bans:

```php
// config/security.php
'trusted_proxies' => ['10.0.0.5'],
```
