<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Attempt counter for request throttling, login lockouts, SMS limits and firewall bans.
 *
 *   RateLimiter::hit('login:' . $email, 900);        // count an attempt, 15-minute window
 *   RateLimiter::tooManyAttempts('login:' . $email, 5);
 *   RateLimiter::availableIn('login:' . $email);      // seconds until unlocked
 *   RateLimiter::clear('login:' . $email);
 *   RateLimiter::prune();                             // delete expired entries (scheduled hourly)
 *
 * Stores (RATE_LIMIT_STORE): "file" (one server) or "database" (shared by several servers).
 *
 * Failure handling: if the store can't be read or written, the limiter FAILS CLOSED by default —
 * the action is treated as rate-limited and a critical log entry is written — so an attacker can't
 * bypass lockouts by exhausting disk space. Set security.rate_limiter.fail_open = true to prefer availability.
 */
final class RateLimiter
{
    private static bool $reportedFailure = false;

    /** Record an attempt and return the number of attempts in the current window. */
    public static function hit(string $key, int $decaySeconds, ?bool $failOpen = null): int
    {
        try {
            return self::store() === 'database' ? self::dbHit($key, $decaySeconds) : self::fileHit($key, $decaySeconds);
        } catch (Throwable $e) {
            return self::failure($e, $failOpen) ? 0 : PHP_INT_MAX;
        }
    }

    public static function attempts(string $key, ?bool $failOpen = null): int
    {
        try {
            $state = self::read($key);
        } catch (Throwable $e) {
            return self::failure($e, $failOpen) ? 0 : PHP_INT_MAX;
        }

        return ($state['reset_at'] ?? 0) > time() ? (int) ($state['attempts'] ?? 0) : 0;
    }

    /** $failOpen overrides the configured failure mode for this check (e.g. the firewall ban list fails open). */
    public static function tooManyAttempts(string $key, int $maxAttempts, ?bool $failOpen = null): bool
    {
        return self::attempts($key, $failOpen) >= $maxAttempts;
    }

    public static function availableIn(string $key): int
    {
        try {
            return max(0, (int) (self::read($key)['reset_at'] ?? 0) - time());
        } catch (Throwable) {
            return 60;
        }
    }

    public static function clear(string $key): void
    {
        try {
            if (self::store() === 'database') {
                DB::table('rate_limits')->where('key', self::hashKey($key))->delete();

                return;
            }
            $file = self::file($key);
            if (is_file($file)) {
                @unlink($file);
            }
        } catch (Throwable $e) {
            self::failure($e);
        }
    }

    /** Delete expired entries. Returns how many were removed. */
    public static function prune(): int
    {
        $now = time();

        if (self::store() === 'database') {
            return DB::table('rate_limits')->where('reset_at', '<', $now)->delete();
        }

        $removed = 0;
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            $state = json_decode((string) @file_get_contents($file), true);
            if (!is_array($state) || (int) ($state['reset_at'] ?? 0) < $now) {
                $removed += (int) @unlink($file);
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------ stores

    private static function store(): string
    {
        return (string) Config::get('security.rate_limiter.store', 'file');
    }

    private static function fileHit(string $key, int $decaySeconds): int
    {
        $handle = @fopen(self::file($key), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Rate limiter: cannot open its state file in ' . self::directory());
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Rate limiter: cannot lock its state file.');
            }
            $state = json_decode((string) stream_get_contents($handle), true);
            $now = time();
            if (!is_array($state) || ($state['reset_at'] ?? 0) <= $now) {
                $state = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];
            }
            $state['attempts']++;

            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, (string) json_encode($state)) === false) {
                throw new RuntimeException('Rate limiter: cannot write its state file.');
            }
            fflush($handle);

            return (int) $state['attempts'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Portable "upsert": increment an unexpired row, otherwise (re)start the window. */
    private static function dbHit(string $key, int $decaySeconds): int
    {
        $hashed = self::hashKey($key);
        $now = time();

        return DB::transaction(static function () use ($hashed, $now, $decaySeconds): int {
            $incremented = DB::table('rate_limits')->where('key', $hashed)->where('reset_at', '>', $now)->increment('attempts');
            if ($incremented === 0) {
                $reset = DB::table('rate_limits')->where('key', $hashed)->update(['attempts' => 1, 'reset_at' => $now + $decaySeconds]);
                if ($reset === 0) {
                    try {
                        DB::table('rate_limits')->insert(['key' => $hashed, 'attempts' => 1, 'reset_at' => $now + $decaySeconds]);
                    } catch (Throwable) {
                        // Another server inserted it at the same moment: count this attempt on that row.
                        DB::table('rate_limits')->where('key', $hashed)->increment('attempts');
                    }
                }
            }

            return (int) (DB::table('rate_limits')->where('key', $hashed)->value('attempts') ?? 1);
        });
    }

    /** @return array{attempts?: int, reset_at?: int} */
    private static function read(string $key): array
    {
        if (self::store() === 'database') {
            $row = DB::table('rate_limits')->where('key', self::hashKey($key))->first();

            return $row === null ? [] : ['attempts' => (int) $row['attempts'], 'reset_at' => (int) $row['reset_at']];
        }

        $file = self::file($key);
        if (!is_file($file)) {
            return [];
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Rate limiter: cannot read its state file.');
        }
        $state = json_decode($contents, true);

        return is_array($state) ? $state : [];
    }

    /** Returns true when the caller should fail OPEN (allow), false to fail CLOSED (block). */
    private static function failure(Throwable $e, ?bool $failOpen = null): bool
    {
        if (!self::$reportedFailure) {
            self::$reportedFailure = true;
            ErrorHandler::report($e);
            Log::security('rate_limiter_unavailable', ['error' => $e->getMessage()]);
        }

        return $failOpen ?? (bool) Config::get('security.rate_limiter.fail_open', false);
    }

    private static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function directory(): string
    {
        $dir = storage_path('cache/ratelimit');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Rate limiter: cannot create $dir");
        }

        return $dir;
    }

    private static function file(string $key): string
    {
        return self::directory() . '/' . self::hashKey($key) . '.json';
    }
}
