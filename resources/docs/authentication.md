# Authentication

A complete sign-in system ships ready to use (like Laravel Breeze):

| Feature | URL |
|---|---|
| Register | `/register` |
| Log in (with "remember me") | `/login` |
| Log out (POST + CSRF) | `/logout` |
| Forgot / reset password | `/forgot-password`, `/reset-password/{token}` |
| Email verification | `/verify-email` |
| Profile, avatar, password change, delete account | `/profile` |
| Dashboard | `/dashboard` |

## Using Auth in code

```php
use Core\Auth;

Auth::check();              // signed in?
Auth::guest();
Auth::user();               // the User model or null   (also: auth(), $request->user())
Auth::id();

Auth::attempt(['email' => $email, 'password' => $password], remember: true);
Auth::login($user);
Auth::logout();
Auth::validateCredentials(['email' => $e, 'password' => $p]);   // check without logging in
Auth::lockoutSeconds($email);                                   // > 0 while locked
```

In Blade: `@auth ... @endauth`, `@guest ... @endguest`, `{{ auth()->name }}`.

## Protecting routes

```php
$router->get('/dashboard', ...)->middleware('auth');             // signed in
$router->get('/billing', ...)->middleware('auth', 'verified');   // + confirmed email
$router->get('/login', ...)->middleware('guest');                // signed out only
```

Guests hitting an `auth` page are sent to `/login` and brought back to that page after signing in (only internal paths, so it can't be abused as an open redirect).

## What happens on login

1. The email is lower-cased; the account/IP pair is checked for a **lockout** (5 failures = 15 minutes).
2. The password is checked with `password_verify` in constant time. Unknown emails take the same time (no enumeration) and show the same message.
3. If the hash uses an old cost/algorithm it is upgraded automatically.
4. The session ID and CSRF secret are regenerated; only the user ID is stored.
5. The session is bound to the browser and to a fingerprint of the password hash.
6. Every success and failure is written to the security log.

## Remember me

When ticked, a 60-character random token is created. The cookie holds it **encrypted**; the database holds only its **HMAC hash**. Logging out rotates the token so stolen cookies stop working. Lifetime: `remember_days` in `config/auth.php`.

## Passwords

- Hashed with bcrypt cost 12 (`config/hashing.php`, or argon2id).
- The `User` model's `'password' => 'hashed'` cast hashes automatically: `$user->password = 'New#Pass1';`
- `strong_password` rule: 8+ characters with upper, lower, number and symbol.
- Changing the password signs out all other devices automatically.

## Password reset

`Core\Auth\Password` sends a single-use link valid for 60 minutes. Tokens are random, stored only as hashes, throttled to one email per minute, and the answer is identical whether or not the email exists.

```php
Password::sendResetLink($email);
Password::reset($email, $token, $newPassword);   // returns Password::PASSWORD_RESET or an error status
```

## Email verification

Set `AUTH_VERIFY_EMAIL=true` (default). New users get a **signed, expiring link** tied to their current email address. Routes with the `verified` middleware are blocked until they click it. Changing the email requires verifying again.

> **Tip:** With `MAIL_MAILER=log`, open `storage/logs/mail.log` to click the link during development.

## When credentials are revoked

`Core\Auth\Credentials` is the single place that decides what stops working. Controllers never update passwords themselves.

| Event | Other sessions | Remember-me cookies | API tokens | This device |
|---|---|---|---|---|
| Password change (`/profile`) | signed out | revoked | revoked | stays signed in |
| Password reset (email link) | signed out | revoked | revoked | must sign in |
| "Sign out of all other devices" | signed out | revoked | revoked | stays signed in |

```php
Credentials::changePassword($user, $newPassword, keepCurrentSession: true);
Credentials::revoke($user, 'compromised');      // e.g. an admin locking a stolen account
```

How it works: every user has a random **security stamp** that is part of each session's fingerprint. Rotating it invalidates every session at once; the remember token is cleared and API tokens are deleted (`auth.revoke_tokens_on_password_change`). The event `auth.credentials_revoked` fires and a security-log entry is written.

## Password reset safety

- The "forgot password" page answers **identically** for registered addresses, unknown addresses and throttled requests (one email per address per minute), and pads its response time, so it can't be used to find out who has an account. With `QUEUE_CONNECTION=database`, emails are sent in the background, which removes timing differences completely.
- A reset token is consumed by **one conditional DELETE in the same transaction** as the password update: two simultaneous requests with the same link can't both succeed.

## Two-factor authentication

Users turn it on under **Profile → Two-factor authentication** (after confirming their password):

1. A secret is generated (160-bit, stored **encrypted**) and shown as a setup key plus an `otpauth://` link for Google Authenticator, Microsoft Authenticator, Authy, 1Password…
2. They confirm with a 6-digit code.
3. Eight **recovery codes** are shown once (stored only as hashes, each usable once).

From then on, signing in needs the password **and** a current code (or a recovery code). Codes use TOTP (RFC 6238, 30-second steps, ±30 s tolerance) and **can't be reused**. Five wrong codes end the attempt.

**Administrators must use 2FA.** The `two-factor` middleware on the admin area sends admins without it to their profile to set it up. Configure the roles with `AUTH_2FA_REQUIRED_ROLES=admin,manager`.

```php
$user->hasTwoFactorEnabled();   $user->requiresTwoFactor();
$user->verifyTwoFactor($code);  $user->regenerateRecoveryCodes();
```

## Confirm password ("sudo mode")

Routes with the `password.confirm` middleware ask for the password again if it wasn't entered in the last 15 minutes (`AUTH_PASSWORD_TIMEOUT` seconds). Used for the admin area, two-factor settings and "sign out other devices". Changing the account email always requires the current password.

## Customising

| What | Where |
|---|---|
| Pages | `resources/views/auth/*.blade.php` |
| Logic | `app/Http/Controllers/Auth/*` |
| Messages | `lang/en/auth.php`, `lang/en/passwords.php` |
| Redirects, lifetimes | `config/auth.php` |
| Lockout limits | `config/security.php` → `login` |

## API tokens

See [Building APIs](api).
