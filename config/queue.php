<?php

return [
    // sync (run jobs immediately) | database (jobs table + "php console queue:work")
    'default' => env('QUEUE_CONNECTION', 'sync'),

    // Job classes that may be restored from the queue. Payloads are JSON; anything else is rejected.
    // "*" at the end matches a namespace.
    'allowed_jobs' => [
        'App\Jobs\*',
    ],
];
