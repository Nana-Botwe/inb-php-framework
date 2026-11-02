<?php

return [
    // bcrypt | argon2id (argon2id needs PHP built with Argon2 support)
    'driver' => env('HASH_DRIVER', 'bcrypt'),

    // bcrypt cost: 12 is a good balance of security and speed in 2026. Raise it as servers get faster.
    'bcrypt_rounds' => (int) env('BCRYPT_ROUNDS', 12),
];
