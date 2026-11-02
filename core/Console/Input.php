<?php

declare(strict_types=1);

namespace Core\Console;

final class Input
{
    /**
     * Split argv into positional arguments and --options.
     *
     * @param string[] $argv
     * @return array{0: string[], 1: array<string, string|bool>}
     */
    public static function parse(array $argv): array
    {
        $positional = [];
        $options = [];

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $options[$name] = $value;
            } elseif (preg_match('/^-([a-zA-Z]+)$/', $arg, $m)) {
                foreach (str_split($m[1]) as $flag) {
                    $options[$flag] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $options];
    }
}
