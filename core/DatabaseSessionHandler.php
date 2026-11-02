<?php

declare(strict_types=1);

namespace Core;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * Sessions stored in the "sessions" table (SESSION_DRIVER=database) so several app servers behind a
 * load balancer share them. Works on every supported database.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly int $lifetime)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $row = DB::table('sessions')->where('id', $id)->first();
        } catch (Throwable $e) {
            ErrorHandler::report($e);

            return '';
        }

        if ($row === null || (int) $row['last_activity'] < time() - $this->lifetime) {
            return '';
        }

        $data = base64_decode((string) $row['payload'], true);

        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool
    {
        $values = [
            'payload' => base64_encode($data),
            'last_activity' => time(),
            'user_id' => $_SESSION['_auth_id'] ?? null,
            'ip_address' => App::instance()->request()?->ip(),
            'user_agent' => mb_substr((string) App::instance()->request()?->userAgent(), 0, 500),
        ];

        try {
            $updated = DB::table('sessions')->where('id', $id)->update($values);
            if ($updated === 0 && DB::table('sessions')->where('id', $id)->doesntExist()) {
                DB::table('sessions')->insert(['id' => $id] + $values);
            }

            return true;
        } catch (Throwable $e) {
            ErrorHandler::report($e);

            return false;
        }
    }

    public function destroy(string $id): bool
    {
        DB::table('sessions')->where('id', $id)->delete();

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return DB::table('sessions')->where('last_activity', '<', time() - $max_lifetime)->delete();
    }

    public function validateId(string $id): bool
    {
        return DB::table('sessions')->where('id', $id)->exists();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
