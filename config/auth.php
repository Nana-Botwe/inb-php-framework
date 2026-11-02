<?php

declare(strict_types=1);

return [
    // Model used by Core\Auth and the column users log in with.
    'model' => App\Models\User::class,
    'identifier' => 'email',

    // Where guests are sent when they hit an "auth" route, and where users land after login.
    'login_route' => '/login',
    'home_route' => '/dashboard',

    // "Remember me" cookie lifetime.
    'remember_days' => 30,

    // Require users to confirm their email before using "verified" routes.
    'verify_email' => (bool) env('AUTH_VERIFY_EMAIL', true),

    'passwords' => [
        'expire' => 60,              // minutes a reset link stays valid
        'throttle' => 60,            // seconds between reset emails for the same address (registered or not)
        'response_floor_ms' => 800,  // minimum response time of "forgot password" (hides timing differences)
    ],

    // What a password change or reset revokes. Sessions on other devices and remember-me cookies
    // are ALWAYS revoked; this controls API tokens too.
    'revoke_tokens_on_password_change' => true,

    // API tokens expire after this many days.
    'token_days' => (int) env('AUTH_TOKEN_DAYS', 90),

    // "Confirm your password" is asked again after this many seconds for sensitive pages.
    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),

    'two_factor' => [
        // Roles that MUST have two-factor authentication enabled to use routes with the "two-factor" middleware.
        'required_roles' => array_values(array_filter(explode(',', (string) env('AUTH_2FA_REQUIRED_ROLES', 'admin')))),
        'issuer' => env('APP_NAME', 'INB PHP Framework'),
        'recovery_codes' => 8,
    ],
];
