<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * Turns common errors into a plain-English explanation and the exact fix.
 */
final class SolutionProvider
{
    /** @return array{title: string, fix: string}|null */
    public static function for(Throwable $e): ?array
    {
        if ($e instanceof HasSolution) {
            return ['title' => 'How to fix this', 'fix' => $e->solution()];
        }

        $message = $e->getMessage();
        $rules = [
            // ---- database
            '/\[2002\]|Connection refused|No connection could be made/i' => ['Database server is not running',
                'Start MySQL in the XAMPP Control Panel, then check DB_HOST and DB_PORT in .env (usually 127.0.0.1 and 3306).'],
            '/\[1045\]|Access denied for user/i' => ['Database login failed',
                'The DB_USERNAME or DB_PASSWORD in .env is wrong, or that user has no rights on the database.'],
            '/\[1049\]|Unknown database/i' => ['The database does not exist',
                'Create it (e.g. in phpMyAdmin) or fix DB_DATABASE in .env, then run: php console migrate'],
            '/42S02|Base table or view not found|no such table/i' => ['A table is missing',
                'Run "php console migrate" to create your tables. If the table is new, create a migration with "php console make:migration create_x_table".'],
            '/42S22|Unknown column|no such column|has no column/i' => ['A column is missing',
                'The query uses a column that is not in the table. Add it with a migration (Schema::table) or fix the column name in your code.'],
            '/23000.*Duplicate entry|UNIQUE constraint failed/i' => ['Duplicate value',
                'A unique column already has this value. Validate it first with the rule unique:table,column.'],
            '/23000.*foreign key constraint|FOREIGN KEY constraint failed/i' => ['Foreign key problem',
                'You are saving or deleting a row that another table depends on. Check the related ID exists, or use ->cascadeOnDelete() in the migration.'],
            '/1366|Incorrect (integer|decimal) value|Data too long/i' => ['Value does not fit the column',
                'Validate the input (e.g. integer, max:255) before saving, or change the column type in a migration.'],
            '/Invalid SQL identifier/i' => ['Unsafe column or table name blocked',
                'Column/table names must be plain words (letters, numbers, _). Never build column names from user input — use a whitelist.'],
            '/Refusing to (update|delete) every row/i' => ['Mass update/delete blocked',
                'Add a ->where(...) condition. This guard stops accidental "UPDATE/DELETE without WHERE" disasters.'],

            // ---- framework
            '/APP_KEY is missing/i' => ['Application key missing', 'Run: php console key:generate'],
            '/View \[(.+?)\] not found/i' => ['View file not found',
                'Create the file resources/views/$1.blade.php (dots become folders: "auth.login" = auth/login.blade.php).'],
            '/Route \[(.+?)\] is not defined/i' => ['Unknown route name',
                'Add ->name(\'$1\') to the route in routes/web.php, or fix the spelling. "php console route:list" shows all names.'],
            '/Middleware \[(.+?)\] not found/i' => ['Unknown middleware',
                'Register an alias for "$1" in config/middleware.php under "aliases".'],
            '/Route action \[(.+?)\] does not exist/i' => ['Controller method missing',
                'Create the public method, or fix the controller/method name in routes/web.php.'],
            '/Relation \[(.+?)\] is not defined/i' => ['Unknown relationship',
                'Add a public method $1() returning hasMany/belongsTo/... (with its return type) to the model.'],
            '/Unknown validation rule \[(.+?)\]/i' => ['Unknown validation rule', 'Check the spelling of the rule "$1". The supported rules are listed at the top of core/Validator.php.'],
            '/Disk \[(.+?)\] is not configured/i' => ['Unknown storage disk', 'Add the "$1" disk to config/filesystems.php.'],
            '/Could not decompress/i' => ['Corrupted compressed file', 'The stored file is damaged or was compressed with an algorithm that is not installed on this server.'],

            // ---- PHP
            '/Class "(.+?)" not found/i' => ['Class not found',
                'Check the namespace and that the file name matches the class name EXACTLY (Linux is case-sensitive). App\\Models\\User must be in app/Models/User.php.'],
            '/Call to undefined method (.+?)\(\)/i' => ['Method does not exist', 'Check the spelling of $1, or add the method to the class.'],
            '/Call to undefined function (.+?)\(\)/i' => ['Function does not exist', 'Check the spelling of $1, or enable the PHP extension that provides it in php.ini.'],
            '/Undefined variable \$(\w+)/i' => ['Variable not defined',
                'Pass it to the view from the controller: view(\'...\', [\'$1\' => $value]) — or check the spelling.'],
            '/Undefined array key "?(.+?)"?$/i' => ['Missing array key', 'Use $array[\'$1\'] ?? null, or check with isset() before reading it.'],
            '/on null|of type null/i' => ['Something is null', 'A value you expected (often a database row) does not exist. Use findOrFail(), check for null, or use the ?-> operator.'],
            '/Maximum execution time/i' => ['Script ran too long', 'Move slow work to a queued job (php console make:job) or process data in chunks with ->chunk().'],
            '/Allowed memory size/i' => ['Out of memory', 'Load less data at once: use ->paginate(), ->chunk() or select only the columns you need.'],
            '/Permission denied|failed to open stream|Failed to open stream/i' => ['File permission problem',
                'Make sure the storage/ folder exists and is writable by the web server.'],
            '/syntax error/i' => ['PHP syntax error', 'Look at the highlighted line (and the one before it) for a missing ; ) } or quote.'],
            '/must be of type (\w+), (\w+) given/i' => ['Wrong value type', 'A function expected $1 but got $2. Cast or validate the value before passing it.'],
        ];

        foreach ($rules as $pattern => [$title, $fix]) {
            if (preg_match($pattern, $message, $m)) {
                $fix = preg_replace_callback('/\$(\d)/', static fn ($r) => $m[(int) $r[1]] ?? '', $fix) ?? $fix;

                return ['title' => $title, 'fix' => $fix];
            }
        }

        return null;
    }
}
