<?php

declare(strict_types=1);

return [
    // Front-end origins allowed to call /api from a browser, e.g. 'https://app.inbitfirm.com'.
    // Avoid '*' in production.
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'X-CSRF-TOKEN'],
    'max_age' => 600,
    'supports_credentials' => false,
];
