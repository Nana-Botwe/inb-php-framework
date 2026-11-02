<?php

declare(strict_types=1);

namespace Core;

/**
 * Minimal PSR-4 autoloader, used when Composer's vendor/autoload.php is absent.
 */
final class Autoloader
{
    /** @param array<string, string> $map namespace prefix => base directory */
    public static function register(array $map): void
    {
        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));
                $file = rtrim($dir, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';

                if (is_file($file)) {
                    require $file;
                    return;
                }
            }
        });
    }
}
