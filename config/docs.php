<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documentation website (/docs)
|--------------------------------------------------------------------------
| Pages live in resources/docs/{slug}.md. Add your own project docs by adding
| a file there and listing it below. Only listed pages can be opened.
*/

return [
    // Turn off on public production sites if you don't want the docs visible: DOCS_ENABLED=false
    'enabled' => (bool) env('DOCS_ENABLED', true),

    'navigation' => [
        'Getting started' => [
            'installation' => 'Installation',
            'configuration' => 'Configuration',
            'structure' => 'Directory structure',
            'lifecycle' => 'Request lifecycle',
        ],
        'The basics' => [
            'routing' => 'Routing',
            'middleware' => 'Middleware',
            'controllers' => 'Controllers',
            'requests' => 'Requests',
            'responses' => 'Responses',
            'views' => 'Views & Blade',
            'validation' => 'Validation',
            'errors' => 'Error handling & logging',
        ],
        'Security' => [
            'security' => 'Security overview',
            'authentication' => 'Authentication',
            'authorization' => 'Authorization & roles',
            'encryption' => 'Encryption, hashing & tokens',
        ],
        'Database' => [
            'database' => 'Database & query builder',
            'models' => 'Models (ORM)',
            'relationships' => 'Relationships',
            'migrations' => 'Migrations',
            'seeding' => 'Seeders & factories',
        ],
        'Features' => [
            'storage' => 'File uploads & compression',
            'mail' => 'Mail & email templates',
            'payments' => 'Payment gateways',
            'sms' => 'SMS gateways',
            'api' => 'Building APIs',
            'queues' => 'Queues',
            'scheduling' => 'Task scheduling',
            'cache' => 'Cache',
            'events' => 'Events',
            'localization' => 'Localization',
            'helpers' => 'Helpers & utilities',
        ],
        'Tools' => [
            'console' => 'Console commands',
            'testing' => 'Testing',
            'deployment' => 'Deployment',
            'operations' => 'Operations & maintenance',
        ],
    ],
];
