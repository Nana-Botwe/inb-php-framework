<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Throwable;

/**
 * Cache with "file" (storage/framework/cache) or "database" (table "cache", shared by several servers) stores.
 *
 *   $stats = Cache::remember('dashboard.stats', 600, fn () => DB::table('orders')->count());
 *   Cache::put('key', $value, 60);  Cache::get('key');  Cache::forget('key');  Cache::flush();
 *
 * Safety: every entry is signed with an HMAC of APP_KEY and checked before it is read, so a tampered
 * cache file/row is ignored. Values are unserialised WITHOUT creating objects unless their classes are
 * listed in config/cache.php → allowed_classes. Cache arrays and scalars (IDs, counts, strings).
 */
final class Cache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = self::readRaw($key);
        $entry = $raw === null ? null : self::decode($raw);

        if ($entry === null || ($entry['e'] !== 0 && $entry['e'] < time())) {
            if ($raw !== null) {
                self::forget($key);
            }

            return $default instanceof Closure ? $default() : $default;
        }

        return $entry['v'];
    }

    public static function has(string $key): bool
    {
        $marker = '__inb_cache_miss__' . bin2hex(random_bytes(4));

        return self::get($key, $marker) !== $marker;
    }

    /** @param int|null $seconds null = forever */
    public static function put(string $key, mixed $value, ?int $seconds = null): bool
    {
        $expires = $seconds === null ? 0 : time() + $seconds;
        $payload = self::encode($value, $expires);

        if (self::store() === 'database') {
            $hashed = hash('sha256', $key);
            try {
                $updated = DB::table('cache')->where('key', $hashed)->update(['value' => $payload, 'expiration' => $expires]);
                if ($updated === 0 && DB::table('cache')->where('key', $hashed)->doesntExist()) {
                    DB::table('cache')->insert(['key' => $hashed, 'value' => $payload, 'expiration' => $expires]);
                }

                return true;
            } catch (Throwable $e) {
                ErrorHandler::report($e);

                return false;
            }
        }

        return file_put_contents(self::path($key), $payload, LOCK_EX) !== false;
    }

    public static function forever(string $key, mixed $value): bool
    {
        return self::put($key, $value);
    }

    /** Store only if missing. Returns false when the key already existed (simple locks / "once" flags). */
    public static function add(string $key, mixed $value, ?int $seconds = null): bool
    {
        if (self::has($key)) {
            return false;
        }

        if (self::store() === 'file') {
            // Atomic create: two processes can't both win.
            $file = self::path($key);
            $handle = @fopen($file, 'x');
            if ($handle === false) {
                return false;
            }
            fwrite($handle, self::encode($value, $seconds === null ? 0 : time() + $seconds));
            fclose($handle);

            return true;
        }

        try {
            DB::table('cache')->insert(['key' => hash('sha256', $key), 'value' => self::encode($value, $seconds === null ? 0 : time() + $seconds), 'expiration' => $seconds === null ? 0 : time() + $seconds]);

            return true;
        } catch (Throwable) {
            return false; // primary key already taken by another process
        }
    }

    public static function remember(string $key, ?int $seconds, Closure $callback): mixed
    {
        $marker = '__inb_cache_miss__' . bin2hex(random_bytes(4));
        $value = self::get($key, $marker);
        if ($value !== $marker) {
            return $value;
        }

        $value = $callback();
        self::put($key, $value, $seconds);

        return $value;
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function increment(string $key, int $by = 1): int
    {
        $value = (int) self::get($key, 0) + $by;
        self::put($key, $value);

        return $value;
    }

    public static function forget(string $key): bool
    {
        if (self::store() === 'database') {
            DB::table('cache')->where('key', hash('sha256', $key))->delete();

            return true;
        }

        $file = self::path($key);

        return !is_file($file) || @unlink($file);
    }

    public static function flush(): void
    {
        if (self::store() === 'database') {
            DB::table('cache')->where('expiration', '>=', 0)->delete();

            return;
        }

        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Remove expired entries (scheduled). */
    public static function prune(): int
    {
        if (self::store() === 'database') {
            return DB::table('cache')->where('expiration', '>', 0)->where('expiration', '<', time())->delete();
        }

        $removed = 0;
        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            $entry = self::decode((string) @file_get_contents($file));
            if ($entry === null || ($entry['e'] !== 0 && $entry['e'] < time())) {
                $removed += (int) @unlink($file);
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------ internals

    private static function encode(mixed $value, int $expires): string
    {
        $body = base64_encode(serialize(['e' => $expires, 'v' => $value]));

        return 'v1:' . Crypt::hmac($body) . ':' . $body;
    }

    /** @return array{e: int, v: mixed}|null */
    private static function decode(string $raw): ?array
    {
        $parts = explode(':', $raw, 3);
        if (count($parts) !== 3 || $parts[0] !== 'v1' || !Crypt::verifyHmac($parts[2], $parts[1])) {
            return null; // unsigned, corrupted or tampered entry
        }

        $data = @unserialize((string) base64_decode($parts[2], true), ['allowed_classes' => self::allowedClasses()]);

        return is_array($data) && array_key_exists('e', $data) ? ['e' => (int) $data['e'], 'v' => $data['v'] ?? null] : null;
    }

    /** @return bool|string[] */
    private static function allowedClasses(): bool|array
    {
        $classes = (array) Config::get('cache.allowed_classes', []);

        return $classes === [] ? false : array_values(array_map('strval', $classes));
    }

    private static function readRaw(string $key): ?string
    {
        if (self::store() === 'database') {
            $row = DB::table('cache')->where('key', hash('sha256', $key))->first();

            return $row === null ? null : (string) $row['value'];
        }

        $file = self::path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);

        return $raw === false ? null : $raw;
    }

    private static function store(): string
    {
        return (string) Config::get('cache.store', 'file');
    }

    private static function directory(): string
    {
        $dir = storage_path('framework/cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function path(string $key): string
    {
        return self::directory() . '/' . hash('sha256', $key) . '.cache';
    }
}
