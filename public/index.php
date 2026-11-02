<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front controller — every request enters here
|--------------------------------------------------------------------------
*/

// Let PHP's built-in server (php console serve) deliver real files such as CSS and images.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_file($file)) {
        return false;
    }
}

$app = require __DIR__ . '/../bootstrap/app.php';

$app->run();
