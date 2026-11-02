# Responses

Every controller action ends with a response. Nothing is printed until the very end, so you never get "headers already sent" errors.

## Types of response

```php
return view('posts.index', ['posts' => $posts]);   // HTML from Blade
return 'Hello';                                    // plain HTML
return ['status' => 'ok'];                         // JSON
return $user;                                      // model -> JSON (hidden fields removed)
return json(['data' => $items], 201);              // JSON with a status code
return response('Not here', 404);
return Core\Response::noContent();                 // 204
```

## Redirects

```php
return redirect('/dashboard');
return redirect(route('posts.show', ['post' => $post]));
return back();                                     // previous page
return Core\Response::intended('/dashboard');      // page the user wanted before logging in
return redirect('https://paystack.com/pay/...');   // external URL
```

### Flash data

Values that survive exactly one redirect — perfect for messages:

```php
return back()->with('success', 'Saved!');
return back()->withErrors(['email' => 'Unknown email'])->withInput($request->only(['email']));
```

The layouts show `success`, `warning`, `error` and `info` messages automatically. Read them anywhere with `session('success')`.

## Headers and cookies

```php
return view('report')
    ->header('X-Report-Version', '2')
    ->withHeaders(['Cache-Control' => 'no-store'])
    ->cookie('theme', 'dark', 60 * 24 * 365);   // minutes; HttpOnly + SameSite=Lax by default
```

Header values are stripped of line breaks, so header injection is impossible.

## Downloads

```php
return Core\Storage::disk('local')->download('invoices/inv-1042.pdf', 'Invoice-1042.pdf');
```

Files compressed on upload are decompressed transparently.

## Errors

```php
abort(404);
abort(403, 'Admins only.');
abort_if(!$user->isAdmin(), 403);
abort_unless($post->published, 404);
```

See [Error handling](errors).

## Security headers

Every response automatically carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, a Content-Security-Policy with a fresh nonce, `Strict-Transport-Security` on HTTPS, and `Cache-Control: no-store` for signed-in pages. Configure them in `config/security.php`.
