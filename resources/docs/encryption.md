# Encryption, hashing & tokens

All cryptography uses well-tested PHP primitives (OpenSSL, `random_bytes`, `password_hash`, `hash_hmac`) with the 256-bit `APP_KEY`.

```bash
php console key:generate          # creates APP_KEY in .env
```

> **Warning:** Changing `APP_KEY` logs everyone out and makes encrypted data, remember-me cookies, signed links and HMAC-hashed tokens invalid. Back it up safely; never commit it.

## Encryption (AES-256-GCM)

Authenticated encryption: tampered data is detected and rejected.

```php
$secret = encrypt(['account' => '0123456789']);   // or Core\Crypt::encrypt()
$data = decrypt($secret);                         // throws if modified
Core\Crypt::tryDecrypt($maybe);                   // null instead of throwing
```

Encrypt a column automatically with a cast:

```php
protected static array $casts = ['ghana_card_number' => 'encrypted'];
```

## Password hashing

```php
$hash = Core\Hash::make($password);        // bcrypt cost 12 (or bcrypt('...'))
Core\Hash::check($password, $hash);
Core\Hash::needsRehash($hash);
```

Use the `'hashed'` cast on password columns to hash automatically. Configure the algorithm (`bcrypt` or `argon2id`) and cost in `config/hashing.php`.

## Secure tokens

`Core\Token` is the toolkit for anything that acts like a password: invite links, one-time codes, API keys, payment references.

```php
use Core\Token;

$plain = Token::random();              // 256-bit, URL-safe
$stored = Token::hash($plain);          // store ONLY this (HMAC-SHA256 with APP_KEY)
Token::equals($stored, Token::hash($input));   // constant-time comparison

$code = Token::otp(6);                  // "493027" — uniform, no modulo bias
$ref = Token::reference('INV');         // "INV_20261019_9f2c..." unguessable reference
```

### Signed tokens

Tamper-proof, expiring, purpose-bound data (readable, not secret — encrypt if it must be hidden):

```php
$t = Token::sign(['user' => 5], minutes: 60, purpose: 'invite');
Token::verify($t, 'invite');     // ['user' => 5]
Token::verify($t, 'reset');      // null: wrong purpose
```

## Signed URLs

Links that can't be altered or reused after expiry — for email verification, unsubscribe links, downloads:

```php
use Core\URL;

$url = URL::temporarySignedRoute('downloads.show', 30, ['file' => 42]);   // valid 30 minutes
$router->get('/downloads/{file}', ...)->name('downloads.show')->middleware('signed');
```

## How the framework stores tokens

| Token | Stored as | Lifetime |
|---|---|---|
| Session ID | file in `storage/framework/sessions` | idle timeout |
| CSRF token | session secret; masked per page | session |
| Remember-me | encrypted cookie + HMAC hash in `users.remember_token` | 30 days, rotated on logout |
| Password reset | HMAC hash in `password_reset_tokens` | 60 minutes, single use |
| Email verification | signed URL (HMAC) | 60 minutes |
| API token | HMAC hash in `personal_access_tokens` (plain text shown once) | 90 days by default |
| Maintenance bypass | HMAC cookie | 12 hours |

Because only hashes are stored, a leaked database backup does not give anyone working tokens.

## Rotating the key

```bash
php console key:rotate
```

The old key moves to `APP_PREVIOUS_KEYS`; decryption, signed URLs, signed tokens and token hashes are checked against the current key first and then the previous ones, so nothing breaks. New data always uses the new key. See [Operations](operations#rotating-app-key).
