<?php

return [
    // Run on every route (the request firewall runs even earlier, in Core\App).
    'global' => [],

    // Applied automatically: "web" to routes/web.php, "api" to routes/api.php.
    'groups' => [
        'web' => [
            Core\Middleware\VerifyCsrfToken::class,
        ],
        'api' => [
            Core\Middleware\HandleCors::class,
            'throttle:60,1',
        ],
    ],

    // Short names for routes: ->middleware('auth'), ->middleware('role:admin'), ->middleware('throttle:5,1')
    'aliases' => [
        'auth' => App\Http\Middleware\Authenticate::class,          // 'auth' = session, 'auth:api' = API token
        'guest' => App\Http\Middleware\RedirectIfAuthenticated::class,
        'role' => App\Http\Middleware\RequireRole::class,
        'verified' => Core\Middleware\EnsureEmailIsVerified::class,
        'signed' => Core\Middleware\ValidateSignature::class,
        'password.confirm' => Core\Middleware\RequirePassword::class,   // re-enter password for sensitive pages
        'two-factor' => Core\Middleware\EnsureTwoFactorEnabled::class,   // admins must have 2FA on
        'throttle' => Core\Middleware\Throttle::class,
        'cors' => Core\Middleware\HandleCors::class,
    ],

    // Paths that skip CSRF checks. Payment webhooks are verified by their signatures instead.
    'csrf_except' => [
        'payments/webhook/*',
    ],
];
