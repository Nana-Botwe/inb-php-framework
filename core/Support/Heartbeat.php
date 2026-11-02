<?php

declare(strict_types=1);

namespace Core\Support;

use Core\Cache;
use Throwable;

/**
 * "I am alive" markers for background processes, checked by php console deploy:verify.
 *
 *   Heartbeat::beat('scheduler');      // schedule:run, every minute
 *   Heartbeat::beat('queue');          // queue:work, at most every 30 seconds
 *   Heartbeat::age('queue');           // seconds since the last beat, or null if never
 *
 * Stored in the cache: with CACHE_STORE=database every server sees every worker.
 */
final class Heartbeat
{
    public static function beat(string $name): void
    {
        try {
            Cache::forever('heartbeat:' . $name, ['time' => time(), 'host' => (string) gethostname(), 'pid' => getmypid()]);
        } catch (Throwable) {
            // A heartbeat must never stop the worker or the scheduler.
        }
    }

    public static function age(string $name): ?int
    {
        $beat = Cache::get('heartbeat:' . $name);

        return is_array($beat) && is_int($beat['time'] ?? null) ? max(0, time() - $beat['time']) : null;
    }

    /** @return array{time: int, host: string, pid: int|false}|null */
    public static function last(string $name): ?array
    {
        $beat = Cache::get('heartbeat:' . $name);

        return is_array($beat) ? $beat : null;
    }
}
