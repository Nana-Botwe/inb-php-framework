<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * String helpers: Str::slug('Hello World') -> "hello-world", Str::random(32), Str::uuid() ...
 */
final class Str
{
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = self::ascii($value);
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', $separator, $value);

        return strtolower(trim($value, $separator));
    }

    public static function ascii(string $value): string
    {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;

        return $converted === false ? $value : $converted;
    }

    /** Cryptographically secure random string (letters and digits). */
    public static function random(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /** RFC 4122 version-4 UUID. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = (string) preg_replace('/\s+/u', '', ucwords($value));

        return strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
    }

    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        return mb_strwidth($value, 'UTF-8') <= $limit
            ? $value
            : rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        return !isset($matches[0]) || mb_strlen($value) === mb_strlen($matches[0]) ? $value : rtrim($matches[0]) . $end;
    }

    /** Naive English pluralisation, good enough for table names. */
    public static function plural(string $value): string
    {
        $lower = strtolower($value);

        return match (true) {
            str_ends_with($lower, 'y') && !preg_match('/[aeiou]y$/', $lower) => substr($value, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $lower) => $value . 'es',
            default => $value . 's',
        };
    }

    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function after(string $subject, string $search): string
    {
        $pos = $search === '' ? false : strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    public static function before(string $subject, string $search): string
    {
        $pos = $search === '' ? false : strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    /** Pattern match with "*" wildcards: Str::is('admin/*', $path) */
    public static function is(string $pattern, string $value): bool
    {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#u';

        return (bool) preg_match($regex, $value);
    }

    public static function mask(string $value, string $character = '*', int $index = 0, ?int $length = null): string
    {
        $segment = mb_substr($value, $index, $length);
        if ($segment === '') {
            return $value;
        }
        $start = mb_substr($value, 0, $index < 0 ? max(0, mb_strlen($value) + $index) : $index);

        return $start . str_repeat($character, mb_strlen($segment)) . mb_substr($value, mb_strlen($start) + mb_strlen($segment));
    }
}
