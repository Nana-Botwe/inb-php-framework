<?php

declare(strict_types=1);

namespace Core;

use Core\Auth\PersonalAccessToken;
use Core\Support\Str;

/**
 * Authentication.
 *
 *   Auth::attempt(['email' => $email, 'password' => $password], remember: true);
 *   Auth::user();  Auth::id();  Auth::check();  Auth::guest();  Auth::logout();
 *
 * Security built in:
 *   - only the user ID is kept in the session; the session ID is regenerated on login/logout
 *   - accounts lock after N failed attempts (config/security.php), failures are logged
 *   - timing is the same whether or not the email exists (no account enumeration)
 *   - the session is bound to the browser (User-Agent) and to the password: changing the
 *     password signs out every other device automatically
 *   - "remember me" uses an encrypted cookie + a hashed token in the database
 *   - API requests authenticate with hashed personal access tokens (Authorization: Bearer ...)
 */
final class Auth
{
    private const SESSION_KEY = '_auth_id';

    private const REMEMBER_COOKIE = 'remember_web';

    /** Used to keep login timing the same whether or not the email exists. */
    private const DUMMY_HASH = '$2y$12$RB.PSkYZhOxbXWUHPjpfSev8z8n5Qsc9ggm2sKFZi0aQQ50OP4ldi';

    private static ?Model $user = null;

    private static bool $resolved = false;

    private static ?PersonalAccessToken $token = null;

    /** @return class-string<Model> */
    public static function model(): string
    {
        return (string) Config::get('auth.model');
    }

    /** @param array<string, mixed> $credentials ['email' => ..., 'password' => ...] */
    public static function attempt(array $credentials, bool $remember = false): bool
    {
        $user = self::attemptCredentials($credentials);
        if ($user === null) {
            return false;
        }

        self::login($user, $remember);
        Log::security('login_success');

        return true;
    }

    /**
     * Check credentials WITH lockout, failure counting and logging, but without logging in
     * (used when a second step such as two-factor authentication must follow).
     *
     * @param array<string, mixed> $credentials
     */
    public static function attemptCredentials(array $credentials): ?Model
    {
        $identifierColumn = (string) Config::get('auth.identifier', 'email');
        $identifier = strtolower(trim((string) ($credentials[$identifierColumn] ?? '')));

        if (self::lockoutSeconds($identifier) > 0) {
            Log::security('login_locked', ['identifier' => Str::mask($identifier, '*', 2)]);

            return null;
        }

        $user = self::validateCredentials($credentials);

        if ($user === null) {
            $minutes = (int) Config::get('security.login.lockout_minutes', 15);
            $attempts = RateLimiter::hit(self::lockKey($identifier), $minutes * 60);
            // Account-wide counter too, so attackers rotating IP addresses are also stopped.
            RateLimiter::hit(self::accountLockKey($identifier), $minutes * 60);
            Log::security('login_failed', ['identifier' => Str::mask($identifier, '*', 2), 'attempts' => $attempts]);

            return null;
        }

        RateLimiter::clear(self::lockKey($identifier));

        return $user;
    }

    /**
     * Check credentials without logging in. Returns the user or null.
     *
     * @param array<string, mixed> $credentials
     */
    public static function validateCredentials(array $credentials): ?Model
    {
        $password = (string) ($credentials['password'] ?? '');
        unset($credentials['password']);

        if ($credentials === []) {
            return null;
        }

        $query = self::model()::query();
        foreach ($credentials as $column => $value) {
            $query->where((string) $column, is_string($value) ? strtolower(trim($value)) : $value);
        }
        $user = $query->first();

        if (!$user instanceof Model) {
            Hash::check($password, self::DUMMY_HASH);

            return null;
        }

        $hash = (string) $user->getAttributes()['password'];
        if (!Hash::check($password, $hash)) {
            return null;
        }

        if (Hash::needsRehash($hash)) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        return $user;
    }

    /** Seconds until this account may try again (0 = not locked). */
    public static function lockoutSeconds(string $identifier): int
    {
        $identifier = strtolower(trim($identifier));
        $key = self::lockKey($identifier);
        $accountKey = self::accountLockKey($identifier);
        $max = (int) Config::get('security.login.max_attempts', 5);
        $accountMax = (int) Config::get('security.login.max_attempts_per_account', 20);

        return max(
            RateLimiter::tooManyAttempts($key, $max) ? RateLimiter::availableIn($key) : 0,
            RateLimiter::tooManyAttempts($accountKey, $accountMax) ? RateLimiter::availableIn($accountKey) : 0,
        );
    }

    public static function login(Model $user, bool $remember = false): void
    {
        Session::regenerate();
        Csrf::refresh();
        Session::set(self::SESSION_KEY, $user->getKey());
        Session::set('_auth_fp', self::fingerprint($user));
        Session::set('_auth_ua', self::agentHash());

        if ($remember) {
            self::setRememberCookie($user);
        }

        self::$user = $user;
        self::$resolved = true;
        Event::dispatch('auth.login', [$user]);
    }

    public static function loginUsingId(int|string $id, bool $remember = false): ?Model
    {
        $user = self::model()::find($id);
        if ($user !== null) {
            self::login($user, $remember);
        }

        return $user;
    }

    /** Use a user for this request only (no session) — e.g. tests or API tokens. */
    public static function setUser(?Model $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        $user = self::$resolved ? self::$user : null;

        if ($user !== null && array_key_exists('remember_token', $user->getAttributes())) {
            // Rotate the remember token so stolen "remember me" cookies stop working.
            $user->forceFill(['remember_token' => null])->save();
        }

        Session::forget(self::SESSION_KEY);
        Session::forget('_auth_fp');
        Session::forget('_auth_ua');
        Session::regenerate();
        Csrf::refresh();
        Response::forgetCookie(self::REMEMBER_COOKIE);

        if ($user !== null) {
            Event::dispatch('auth.logout', [$user]);
        }

        self::$user = null;
        self::$resolved = true;
        self::$token = null;
    }

    public static function user(): ?Model
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;
        self::$user = self::userFromSession() ?? self::userFromRememberCookie();

        return self::$user;
    }

    public static function id(): int|string|null
    {
        return self::user()?->getKey();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /** Authenticate the current request with an API token (used by the "auth:api" middleware). */
    public static function authenticateToken(?string $plainToken): ?Model
    {
        $token = $plainToken !== null ? PersonalAccessToken::findToken($plainToken) : null;
        if ($token === null) {
            return null;
        }

        $token->forceFill(['last_used_at' => date('Y-m-d H:i:s')])->save();
        self::$token = $token;
        self::setUser($token->tokenable());

        return self::$user;
    }

    public static function token(): ?PersonalAccessToken
    {
        return self::$token;
    }

    /** Forget the cached user (call between simulated requests in tests). */
    public static function flush(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$token = null;
    }

    // ------------------------------------------------------------------ internals

    private static function userFromSession(): ?Model
    {
        $id = Session::get(self::SESSION_KEY);
        if ($id === null) {
            return null;
        }

        $user = self::model()::find($id);

        $agentMismatch = Config::get('security.bind_session_to_user_agent', true)
            && !hash_equals((string) Session::get('_auth_ua', ''), self::agentHash());

        if ($user === null || $agentMismatch || !hash_equals((string) Session::get('_auth_fp', ''), self::fingerprint($user))) {
            if ($user !== null) {
                Log::security($agentMismatch ? 'session_agent_mismatch' : 'session_invalidated');
            }
            Session::forget(self::SESSION_KEY);
            Session::regenerate();

            return null;
        }

        return $user;
    }

    private static function userFromRememberCookie(): ?Model
    {
        $cookie = App::instance()->request()?->cookie(self::REMEMBER_COOKIE);
        $payload = is_string($cookie) ? Crypt::tryDecrypt($cookie) : null;

        if (!is_array($payload) || !isset($payload['id'], $payload['token'])) {
            return null;
        }

        $user = self::model()::find($payload['id']);
        $stored = $user?->getAttributes()['remember_token'] ?? null;

        if ($user === null || !is_string($stored) || $stored === '' || !Token::matches($stored, (string) $payload['token'])) {
            Response::forgetCookie(self::REMEMBER_COOKIE);

            return null;
        }

        self::login($user); // fresh session, keeps the existing remember cookie

        return $user;
    }

    private static function setRememberCookie(Model $user): void
    {
        $token = Str::random(60);
        $user->forceFill(['remember_token' => Token::hash($token)])->save();

        $minutes = (int) Config::get('auth.remember_days', 30) * 24 * 60;
        Response::queueCookie(self::REMEMBER_COOKIE, Crypt::encrypt(['id' => $user->getKey(), 'token' => $token]), $minutes);
    }

    /**
     * Session fingerprint. Uses the user's security stamp (rotated by Credentials on password change,
     * reset or "sign out everywhere"); falls back to the password hash for tables without the column.
     * When it changes, every existing session of that user stops working.
     */
    private static function fingerprint(Model $user): string
    {
        $attributes = $user->getAttributes();
        $secret = (string) (($attributes['security_stamp'] ?? null) ?: ($attributes['password'] ?? ''));

        return hash('sha256', $secret . '|' . $user->getKey());
    }

    private static function accountLockKey(string $identifier): string
    {
        return 'login-account:' . $identifier;
    }

    /** @internal Re-bind the current session after the user's credentials changed (keeps this device signed in). */
    public static function refreshSession(Model $user): void
    {
        if ((string) Session::get(self::SESSION_KEY) !== (string) $user->getKey()) {
            return;
        }

        Session::regenerate();
        Csrf::refresh();
        Session::set('_auth_fp', self::fingerprint($user));
        self::$user = $user;
        self::$resolved = true;
    }

    private static function agentHash(): string
    {
        return hash('sha256', (string) App::instance()->request()?->userAgent());
    }

    private static function lockKey(string $identifier): string
    {
        return 'login:' . $identifier . '|' . (App::instance()->request()?->ip() ?? 'cli');
    }
}
