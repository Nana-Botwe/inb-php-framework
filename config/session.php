<?php

return [
    // file (one server) | database (several servers behind a load balancer; table "sessions")
    'driver' => env('SESSION_DRIVER', 'file'),

    'name' => env('SESSION_NAME', 'app_session'),

    // Minutes of INACTIVITY before the session expires (enforced on the server).
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    // Minutes after sign-in after which a session always ends, however active (enforced on the server).
    // "Remember me" users are signed back in silently with a fresh session.
    'absolute_lifetime' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),

    // Send the cookie over HTTPS only (auto-enabled when the request is HTTPS).
    'secure' => (bool) env('SESSION_SECURE_COOKIE', false),
    'same_site' => 'Lax',

    // Store session files inside the project instead of the shared system temp folder.
    'path' => base_path('storage/framework/sessions'),
];
