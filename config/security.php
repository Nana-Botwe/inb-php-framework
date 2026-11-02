<?php

return [
    /*
    | Headers sent with every response.
    */
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],

    // Sent only over HTTPS: tells browsers to always use HTTPS for this site.
    'hsts' => 'max-age=31536000; includeSubDomains',

    /*
    | Content-Security-Policy (blocks injected scripts). {nonce} is replaced per request.
    | Add a CDN here before loading scripts/styles from it. Set to [] to disable.
    */
    'csp' => [
        'default-src' => "'self'",
        'script-src' => "'self' 'nonce-{nonce}' https://cdn.jsdelivr.net",
        'style-src' => "'self' 'nonce-{nonce}' https://cdn.jsdelivr.net",
        'img-src' => "'self' data:",
        'font-src' => "'self' data: https://cdn.jsdelivr.net",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
    ],

    /*
    | Login protection: lock an account after this many failed attempts.
    */
    'login' => [
        'max_attempts' => 5,                 // per account + IP
        'max_attempts_per_account' => 20,    // per account from ANY IP (stops distributed guessing)
        'lockout_minutes' => 15,
    ],

    'rate_limiter' => [
        // file (one server) | database (several servers share the "rate_limits" table)
        'store' => env('RATE_LIMIT_STORE', 'file'),
        // If the store fails: false = treat as limited (secure, default), true = allow (available).
        'fail_open' => (bool) env('RATE_LIMIT_FAIL_OPEN', false),
    ],

    // IPs or CIDR ranges of YOUR load balancers/proxies (Nginx, Cloudflare...) allowed to set
    // X-Forwarded-For / X-Forwarded-Proto. Leave empty unless you are behind one.
    // Example (comma separated in .env): TRUSTED_PROXIES=10.0.0.0/8,173.245.48.0/20
    // Your proxy should APPEND the connecting IP to X-Forwarded-For (Nginx: $proxy_add_x_forwarded_for);
    // the framework reads the chain from the right, skipping trusted proxies. See docs/deployment.
    // '*' trusts every hop — only safe if the app server is unreachable except through the proxy.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // Sign the user out if the browser (User-Agent) changes mid-session (stolen cookie).
    'bind_session_to_user_agent' => true,

    // Check the Origin/Referer header on POST/PUT/PATCH/DELETE in addition to the CSRF token.
    'check_origin' => true,

    /*
    | File uploads (Core\Upload).
    */
    'uploads' => [
        'max_kb' => 2048,
    ],
];
