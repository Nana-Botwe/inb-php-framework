<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * Coloured terminal output (colours are disabled automatically when not supported).
 */
final class Output
{
    private static ?bool $colors = null;

    public static function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function success(string $message): void
    {
        self::line(self::color('32', $message));
    }

    public static function info(string $message): void
    {
        self::line(self::color('36', $message));
    }

    public static function warn(string $message): void
    {
        self::line(self::color('33', $message));
    }

    public static function error(string $message): void
    {
        fwrite(STDERR, self::color('31', $message) . PHP_EOL);
    }

    public static function title(string $message): void
    {
        self::line(self::color('1;35', $message));
    }

    public static function muted(string $message): string
    {
        return self::color('2', $message);
    }

    public static function color(string $code, string $text): string
    {
        self::$colors ??= self::supportsColors();

        return self::$colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    /** @param string[] $headers @param array<int, array<int, string>> $rows */
    public static function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $format = static function (array $cells) use ($widths): string {
            $out = [];
            foreach (array_values($cells) as $i => $cell) {
                $out[] = str_pad((string) $cell, ($widths[$i] ?? 0) + strlen((string) $cell) - mb_strlen((string) $cell));
            }

            return '  ' . implode('  ', $out);
        };

        self::line(self::color('1', $format($headers)));
        self::line('  ' . implode('  ', array_map(static fn ($w) => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            self::line($format($row));
        }
    }

    private static function supportsColors(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true);
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
