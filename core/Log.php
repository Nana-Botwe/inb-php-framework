<?php

declare(strict_types=1);

namespace Core;

/**
 * Writes JSON lines to storage/logs. Security events (failed logins, CSRF failures,
 * forbidden access, rejected uploads) go to security-YYYY-MM-DD.log so you can audit them.
 */
final class Log
{
    /** @param array<string, mixed> $context Never put passwords or tokens in here. */
    public static function security(string $event, array $context = []): void
    {
        self::write('security', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('app', $message, $context);
    }

    /** Something failed and needs attention (still written to the app log). @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('app', $message, ['level' => 'error'] + $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $channel, string $message, array $context): void
    {
        $request = App::instance()->request();

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'event' => $message,
            'ip' => $request?->ip(),
            'method' => $request?->method(),
            'path' => $request?->path(),
            'user_id' => Session::get('_auth_id'),
        ] + $context;

        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            "$dir/$channel-" . date('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
