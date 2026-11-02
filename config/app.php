<?php

return [
    'name' => env('APP_NAME', 'INB PHP Framework'),

    // local | staging | production
    'env' => env('APP_ENV', 'production'),

    // Detailed error pages. MUST be false on a live server.
    'debug' => (bool) env('APP_DEBUG', false),

    // Public URL, used for links in emails and the CLI (e.g. https://inbitfirm.com).
    'url' => env('APP_URL', ''),

    'timezone' => env('APP_TIMEZONE', 'Africa/Accra'),

    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => 'en',

    // 256-bit key for encryption, signed URLs and tokens. Generate with: php console key:generate
    'key' => env('APP_KEY', ''),

    // Old keys still accepted for decrypting and verifying (newest first) after "php console key:rotate".
    'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_PREVIOUS_KEYS', ''))))),

    // Hosts this app answers to (comma separated). Empty = the host of APP_URL (+ localhost when APP_ENV=local).
    // Requests for any other Host header are rejected, so links in emails can't be poisoned.
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    // Service providers booted on every request (see app/Providers).
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
];
