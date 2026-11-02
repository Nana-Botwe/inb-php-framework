<?php

declare(strict_types=1);

namespace Core;

/**
 * Loads KEY=VALUE pairs from a .env file. Real server environment variables win.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? self::$vars[$key] ?? null;
        }

        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
