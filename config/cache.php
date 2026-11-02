<?php

return [
    // file (one server) | database (several servers share the "cache" table)
    'store' => env('CACHE_STORE', 'file'),

    // Classes that may be restored from the cache. Empty = none: only arrays and scalars come back,
    // which removes object-injection risk if cache storage is ever tampered with.
    // Example: [Core\Support\Collection::class]
    'allowed_classes' => [],
];
