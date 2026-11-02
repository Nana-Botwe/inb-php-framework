<?php

declare(strict_types=1);

namespace Core\Sms;

final class SmsResult
{
    /**
     * @param string[] $messageIds
     * @param string[] $failed Numbers that were rejected.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $gateway,
        public readonly bool $successful,
        public readonly array $messageIds = [],
        public readonly array $failed = [],
        public readonly string $message = '',
        public readonly array $raw = [],
    ) {
    }
}
