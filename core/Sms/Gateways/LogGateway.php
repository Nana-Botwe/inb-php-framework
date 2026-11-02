<?php

declare(strict_types=1);

namespace Core\Sms\Gateways;

use Core\Sms\Gateway;
use Core\Sms\SmsResult;

/**
 * Development driver: writes messages to storage/logs/sms.log instead of sending them.
 */
final class LogGateway extends Gateway
{
    /** @var array<int, array{to: string[], message: string, from: ?string}> */
    public static array $sent = [];

    public function name(): string
    {
        return 'log';
    }

    public function send(array $to, string $message, ?string $from = null): SmsResult
    {
        self::$sent[] = ['to' => $to, 'message' => $message, 'from' => $from];

        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/sms.log', sprintf("[%s] to=%s from=%s\n%s\n\n", date('Y-m-d H:i:s'), implode(',', $to), $from ?? '-', $message), FILE_APPEND | LOCK_EX);

        return new SmsResult($this->name(), true, array_map(static fn () => bin2hex(random_bytes(6)), $to));
    }
}
