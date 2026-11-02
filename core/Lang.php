<?php

declare(strict_types=1);

namespace Core;

use Core\Support\Arr;

/**
 * Translations from lang/{locale}/{file}.php:
 *   __('auth.failed')  ·  __('Welcome, :name', ['name' => $user->name])  ·  trans_choice('messages.apples', 3)
 */
final class Lang
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    private static ?string $locale = null;

    public static function locale(): string
    {
        return self::$locale ?? (string) Config::get('app.locale', 'en');
    }

    public static function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale)) {
            self::$locale = $locale;
        }
    }

    /** @param array<string, mixed> $replace */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        $line = self::line($key, $locale) ?? self::line($key, (string) Config::get('app.fallback_locale', 'en'));

        return self::replace(is_string($line) ? $line : $key, $replace);
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return is_string(self::line($key, $locale ?? self::locale()));
    }

    /** "apple|apples" or "{0} none|{1} one|[2,*] many" */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        $parts = explode('|', self::get($key));
        $chosen = $parts[count($parts) > 1 && $count !== 1 ? 1 : 0];

        foreach ($parts as $part) {
            if (preg_match('/^\{(\d+)\}\s*(.*)$/s', $part, $m) && (int) $m[1] === $count) {
                $chosen = $m[2];
                break;
            }
            if (preg_match('/^\[(\d+),(\d+|\*)\]\s*(.*)$/s', $part, $m) && $count >= (int) $m[1] && ($m[2] === '*' || $count <= (int) $m[2])) {
                $chosen = $m[3];
                break;
            }
        }

        return self::replace($chosen, $replace + ['count' => $count]);
    }

    private static function line(string $key, string $locale): mixed
    {
        if (!str_contains($key, '.') || str_contains($key, ' ')) {
            return self::json($locale)[$key] ?? null;
        }

        [$file, $item] = explode('.', $key, 2);

        if (!isset(self::$loaded[$locale][$file])) {
            $path = base_path("lang/$locale/$file.php");
            self::$loaded[$locale][$file] = is_file($path) && preg_match('/^\w+$/', $file) ? (array) require $path : [];
        }

        return Arr::get(self::$loaded[$locale][$file], $item);
    }

    /** @return array<string, string> lang/{locale}.json for sentence-style keys */
    private static function json(string $locale): array
    {
        if (!isset(self::$loaded[$locale]['__json'])) {
            $path = base_path("lang/$locale.json");
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            self::$loaded[$locale]['__json'] = is_array($data) ? $data : [];
        }

        return self::$loaded[$locale]['__json'];
    }

    /** @param array<string, mixed> $replace */
    private static function replace(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(
                [':' . $key, ':' . ucfirst((string) $key), ':' . strtoupper((string) $key)],
                [(string) $value, ucfirst((string) $value), strtoupper((string) $value)],
                $line,
            );
        }

        return $line;
    }
}
