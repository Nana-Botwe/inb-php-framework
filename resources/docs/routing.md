# Routing

Every URL of your site is listed in `routes/web.php` (pages) or `routes/api.php` (API). A URL that is not listed returns a 404 — nothing is reachable by accident.

## Basic routes

```php
use App\Http\Controllers\PostController;

$router->get('/', [HomeController::class, 'welcome'])->name('home');
$router->get('/about', fn () => view('about'));
$router->post('/posts', [PostController::class, 'store']);
$router->put('/posts/{id}', [PostController::class, 'update']);
$router->patch('/posts/{id}', [PostController::class, 'update']);
$router->delete('/posts/{id}', [PostController::class, 'destroy']);
$router->match(['GET', 'POST'], '/contact', [ContactController::class, 'handle']);
$router->any('/ping', fn () => 'pong');
```

Shortcuts:

```php
$router->view('/terms', 'pages.terms', ['updated' => '2026-01-01']);
$router->redirect('/old-page', '/new-page', 301);
$router->fallback(fn () => view('errors.error', ['status' => 404, 'message' => '', 'errorId' => null]));
```

> **Note:** HTML forms can only send GET and POST. For PUT/PATCH/DELETE add `@method('DELETE')` inside the form — see [Views](views#forms).

## Route parameters

```php
$router->get('/posts/{id}', [PostController::class, 'show']);
$router->get('/users/{user}/posts/{post}', [PostController::class, 'forUser']);
$router->get('/archive/{year?}', [ArchiveController::class, 'index']);   // optional
```

Parameters are passed to your method **by name**:

```php
public function show(int $id): Response   // "abc" is rejected with a 404 because $id is an int
```

### Constraints

```php
$router->get('/posts/{id}', ...)->whereNumber('id');
$router->get('/posts/{slug}', ...)->where('slug', '[a-z0-9-]+');
$router->get('/files/{year:\d+}', ...);     // inline constraint (use where() for patterns containing { })
$router->get('/files/{year}', ...)->where('year', '\d{4}');
```

## Route model binding

Type-hint a model and the framework loads it for you — or returns a 404 if the row doesn't exist:

```php
$router->get('/posts/{post}', [PostController::class, 'show']);

public function show(Post $post): Response
{
    return $this->view('posts.show', ['post' => $post]);
}
```

## Named routes

```php
$router->get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');

route('profile.edit');                        // "/my-shop/profile"
route('posts.show', ['post' => $post]);       // models are turned into their ID
route('posts.index', ['page' => 2]);          // extra values become ?page=2
route('password.reset', ['token' => $t], true); // absolute URL (for emails)
```

In Blade: `<a href="{{ route('profile.edit') }}">Profile</a>`. List them all with `php console route:list`.

## Groups

```php
$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'role:admin'], 'as' => 'admin.'], function (Router $router) {
    $router->get('/users', [UserController::class, 'index'])->name('users.index');   // name: admin.users.index
});

// Fluent style
$router->prefix('reports')->middleware('auth')->name('reports.')->group(function (Router $router) {
    $router->get('/sales', [ReportController::class, 'sales'])->name('sales');
});
```

## Resource routes

One line creates the seven standard CRUD routes:

```php
$router->resource('photos', PhotoController::class);
$router->resource('photos', PhotoController::class, ['only' => ['index', 'show']]);
$router->apiResource('photos', PhotoController::class);   // without create/edit (for APIs)
```

| Method | URI | Action | Name |
|---|---|---|---|
| GET | /photos | index | photos.index |
| GET | /photos/create | create | photos.create |
| POST | /photos | store | photos.store |
| GET | /photos/{photo} | show | photos.show |
| GET | /photos/{photo}/edit | edit | photos.edit |
| PUT/PATCH | /photos/{photo} | update | photos.update |
| DELETE | /photos/{photo} | destroy | photos.destroy |

Generate the controller with `php console make:controller PhotoController --resource`.

## Middleware on routes

```php
$router->get('/dashboard', ...)->middleware('auth', 'verified');
$router->post('/login', ...)->middleware('throttle:10,1');   // 10 per minute
```

See [Middleware](middleware).

## Web vs API routes

| | `routes/web.php` | `routes/api.php` |
|---|---|---|
| URL prefix | none | `/api` |
| Middleware group | `web` (CSRF protection) | `api` (CORS + 60 requests/min) |
| Authentication | session (`auth`) | tokens (`auth:api`) |

## Current route

```php
request()->is('admin/*');          // path matching
request()->routeIs('admin.*');     // name matching
is_active('dashboard');            // in views, for active menu links
```
