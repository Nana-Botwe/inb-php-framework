<?php

declare(strict_types=1);

namespace Core\Exceptions;

use RuntimeException;

final class DatabaseException extends RuntimeException implements HasSolution
{
    public function solution(): string
    {
        $message = $this->getMessage();

        return match (true) {
            str_contains($message, '[2002]') || stripos($message, 'refused') !== false
                => 'The database server is not running or not reachable. Start MySQL in the XAMPP Control Panel and check DB_HOST / DB_PORT in .env.',
            str_contains($message, '[1045]') => 'Wrong DB_USERNAME or DB_PASSWORD in .env.',
            str_contains($message, '[1049]') => 'The database in DB_DATABASE does not exist. Create it in phpMyAdmin, then run: php console migrate',
            default => 'Check the DB_* settings in .env and that the database server is running.',
        };
    }
}
