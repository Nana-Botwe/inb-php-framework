<?php

return [
    'default' => 'local',

    'disks' => [
        // Private files (invoices, ID documents...). Not reachable by URL — serve them via a controller.
        'local' => [
            'root' => base_path('storage/app/private'),
        ],

        // Public files (avatars, product images). PHP/script execution is disabled in this folder.
        'public' => [
            'root' => base_path('public/uploads'),
            'url' => '/uploads',
        ],
    ],
];
