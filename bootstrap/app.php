<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap: autoloading + application instance
|--------------------------------------------------------------------------
| Works with Composer (vendor/autoload.php) or without it (built-in PSR-4 autoloader).
*/

$basePath = dirname(__DIR__);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('INB PHP Framework requires PHP 8.2 or newer (8.1 is end-of-life). This server runs PHP ' . PHP_VERSION . '.');
}

if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
} else {
    require $basePath . '/core/Autoloader.php';
    Core\Autoloader::register([
        'App\\' => $basePath . '/app',
        'Core\\' => $basePath . '/core',
        'Database\\Factories\\' => $basePath . '/database/factories',
        'Database\\Seeders\\' => $basePath . '/database/seeders',
        'Tests\\' => $basePath . '/tests',
    ]);
    require $basePath . '/core/helpers.php';
}

return new Core\App($basePath);
