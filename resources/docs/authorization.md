# Authorization & roles

Authentication answers "who are you?". Authorization answers "are you allowed to do this?" — and must be checked on the server for **every** record, not just by hiding buttons.

> **Security:** The classic bug is `/invoices/{id}` showing any invoice to anyone who changes the number in the URL (an "IDOR"). Policies make the ownership check a one-liner, so it is never forgotten.

## Policies (recommended)

A policy holds the rules for one model. It is found automatically by name: `App\Models\Post` → `App\Policies\PostPolicy`.

```bash
php console make:policy PostPolicy --model=Post
```

```php
namespace App\Policies;

final class PostPolicy
{
    /** Runs first: true = allow everything (e.g. admins), null = continue. */
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->isAdmin() ? true : null;
    }

    public function view(User $user, Post $post): bool   { return $post->user_id === $user->id || $post->published; }
    public function update(User $user, Post $post): bool { return $post->user_id === $user->id; }
    public function delete(User $user, Post $post): bool { return $post->user_id === $user->id; }
    public function create(User $user): bool              { return $user->hasVerifiedEmail(); }
}
```

Use it:

```php
$this->authorize('update', $post);            // controller: 403 + security-log entry when denied
$this->authorize('create', Post::class);      // abilities without a record
Gate::allows('view', $post);   Gate::denies('delete', $post);
$user->can('update', $post);   $user->cannot('delete', $post);
```

```blade
@can('update', $post) <a href="{{ route('posts.edit', ['post' => $post]) }}">Edit</a> @endcan
@cannot('delete', $post) <span>Read only</span> @endcannot
```

**Secure defaults:** a missing policy method denies; an unknown ability denies; guests are denied unless the method's user parameter is nullable (`?User $user`).

`php console make:controller PostController --resource` generates a controller whose every action calls `$this->authorize(...)`, lists only the signed-in user's own records, sets the owner on the server — and creates the policy for you.

## Abilities without a model

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define('view-reports', fn (User $user) => $user->hasRole('admin', 'manager'));
Gate::before(fn (?User $user) => $user?->hasRole('super-admin') ? true : null);
```

## Roles

Users have a `role` column (`user` by default). It is **not** in `$fillable`, so nobody can give themselves a role through a form.

```bash
php console user:role you@example.com admin
```

```php
$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'verified', 'role:admin', 'two-factor', 'password.confirm']], ...);
$router->get('/reports', ...)->middleware('auth', 'role:admin,manager');
```

Users without the role get a **403 page**, and the attempt is logged.

## Sensitive areas: confirm password & two-factor

| Middleware | Effect |
|---|---|
| `password.confirm` | the user must have re-entered their password in the last 15 minutes (`AUTH_PASSWORD_TIMEOUT`) |
| `two-factor` | users whose role is in `AUTH_2FA_REQUIRED_ROLES` (default `admin`) must have two-factor authentication on |

The admin area uses both. See [Authentication](authentication#two-factor-authentication).

## Admin area

`/admin/users` lists users (search, pagination, change roles). Admins can't change their own role, so nobody locks themselves out by accident.

## API token abilities

```php
$token = $user->createToken('reporting-bot', ['reports:read']);
abort_unless($request->user()->tokenCan('reports:read'), 403);
```
