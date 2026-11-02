<?php

declare(strict_types=1);

namespace Core;

/**
 * Secure session wrapper with flash data (values that live for exactly one more request).
 */
final class Session
{
    private const FLASH = '_flash';

    private static bool $stateless = false;

    /** @var array<string, mixed>|null session data parked while a stateless (API) request runs in CLI/tests */
    private static ?array $parked = null;

    /**
     * Stateless request (API): no PHP session is started and no cookie is sent. Session helpers work
     * on an empty in-memory array that is discarded after the request.
     */
    public static function startStateless(): void
    {
        self::$stateless = true;
        self::$parked = $_SESSION ?? [];
        $_SESSION = [];
    }

    /** @internal Called by the kernel when a request ends. */
    public static function endRequest(): void
    {
        if (self::$stateless) {
            $_SESSION = PHP_SAPI === 'cli' ? (self::$parked ?? []) : [];
            self::$parked = null;
            self::$stateless = false;
        }
    }

    public static function isStateless(): bool
    {
        return self::$stateless;
    }

    /** @param array<string, mixed> $config */
    public static function start(array $config, bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) ($config['lifetime'] ?? 120) * 60;
        $absolute = (int) ($config['absolute_lifetime'] ?? 720) * 60;

        // CLI (tests/console): keep an in-memory session instead of sending cookies.
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            self::expireIfIdle($lifetime, $absolute);
            self::ageFlashData();
            return;
        }

        // Shared session storage for several app servers: SESSION_DRIVER=database.
        if (($config['driver'] ?? 'file') === 'database') {
            session_set_save_handler(new DatabaseSessionHandler($lifetime), true);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        $savePath = (string) ($config['path'] ?? '');
        if ($savePath !== '') {
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0770, true);
            }
            session_save_path($savePath);
        }

        session_name((string) ($config['name'] ?? 'app_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) ($config['secure'] ?? false) || $secure,
            'httponly' => true,
            'samesite' => (string) ($config['same_site'] ?? 'Lax'),
        ]);

        session_start();
        self::expireIfIdle($lifetime, $absolute);
        self::ageFlashData();
    }

    /**
     * Server-side expiry (the cookie alone can't be trusted):
     *  - idle timeout: SESSION_LIFETIME minutes without activity
     *  - absolute timeout: SESSION_ABSOLUTE_LIFETIME minutes after the session started, however active
     */
    private static function expireIfIdle(int $lifetime, int $absolute = 0): void
    {
        $now = time();
        $last = $_SESSION['_last_activity'] ?? null;
        $started = $_SESSION['_started_at'] ?? null;

        $idleExpired = is_int($last) && $now - $last > $lifetime;
        $absoluteExpired = $absolute > 0 && is_int($started) && $now - $started > $absolute;

        if ($idleExpired || $absoluteExpired) {
            $_SESSION = [];
            self::regenerate();
        }

        $_SESSION['_started_at'] ??= $now;
        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Read a value and remove it. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    /** Store a value for the next request only. */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH]['new'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH]['new'][$key] ?? $_SESSION[self::FLASH]['old'][$key] ?? $default;
    }

    /** Last request's flash data becomes readable now and is discarded after this request. */
    private static function ageFlashData(): void
    {
        $_SESSION[self::FLASH] = ['old' => $_SESSION[self::FLASH]['new'] ?? [], 'new' => []];
    }

    /** Issue a new session ID (call on login/logout to prevent session fixation). */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();
    }
}
