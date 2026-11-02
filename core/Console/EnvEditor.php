<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * Safely adds/updates KEY=value lines in .env files (used by key:generate and the *:install commands).
 */
final class EnvEditor
{
    public static function set(string $file, string $key, string $value): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid .env key [$key].");
        }

        $line = $key . '=' . self::quote($value);
        $contents = is_file($file) ? (string) file_get_contents($file) : '';

        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $contents)) {
            $contents = (string) preg_replace_callback('/^' . preg_quote($key, '/') . '=.*$/m', static fn () => $line, $contents);
        } else {
            $contents = rtrim($contents, "\r\n") . ($contents === '' ? '' : PHP_EOL) . $line . PHP_EOL;
        }

        file_put_contents($file, $contents, LOCK_EX);
    }

    /** Add a key only when it is not there yet (keeps existing credentials). */
    public static function add(string $file, string $key, string $value = '', ?string $comment = null): bool
    {
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $contents)) {
            return false;
        }

        if ($comment !== null && !str_contains($contents, '# ' . $comment)) {
            $contents = rtrim($contents, "\r\n") . PHP_EOL . PHP_EOL . '# ' . $comment . PHP_EOL;
            file_put_contents($file, $contents, LOCK_EX);
        }

        self::set($file, $key, $value);

        return true;
    }

    public static function get(string $file, string $key): ?string
    {
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $m)) {
            return trim(trim($m[1]), '"\'');
        }

        return null;
    }

    private static function quote(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return $value === '' || preg_match('/^[A-Za-z0-9_.:\/+=@-]+$/', $value) ? $value : '"' . str_replace('"', '\"', $value) . '"';
    }
}
