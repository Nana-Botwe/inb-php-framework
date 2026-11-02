<?php

return [
    // Backups kept per connection by "php console db:backup".
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Full paths if the tools are not on PATH. XAMPP example: H:\xampp\mysql\bin\mysqldump.exe
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
    'psql' => env('BACKUP_PSQL', 'psql'),

    // Run "db:backup" every night from the scheduler.
    'schedule' => (bool) env('BACKUP_SCHEDULE', false),
];
