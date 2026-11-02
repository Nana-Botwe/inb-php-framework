<?php

declare(strict_types=1);

return [
    // log (writes to storage/logs/mail.log) | smtp | array (tests)
    'default' => env('MAIL_MAILER', 'log'),

    // Email template: modern | classic | minimal  — switch with: php console mail:theme classic
    'theme' => env('MAIL_THEME', 'modern'),
    'brand_color' => env('MAIL_BRAND_COLOR', '#4f46e5'),
    'company' => env('MAIL_COMPANY', env('APP_NAME', 'INB PHP Framework')),
    'address' => env('MAIL_COMPANY_ADDRESS', 'Accra, Ghana'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'INB PHP Framework')),
    ],

    'mailers' => [
        'smtp' => [
            'host' => env('MAIL_HOST', 'smtp.gmail.com'),
            'port' => (int) env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),   // tls (587) | ssl (465) | none
            'username' => env('MAIL_USERNAME', ''),
            'password' => env('MAIL_PASSWORD', ''),
            'timeout' => 15,
            'verify_peer' => true,
        ],
    ],
];
