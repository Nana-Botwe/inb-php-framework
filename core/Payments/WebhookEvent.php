<?php

declare(strict_types=1);

namespace Core\Payments;

/**
 * A webhook whose signature was verified. Even so, call verify() before delivering an order:
 * the event tells you WHICH payment changed; verify() tells you its real status.
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $gateway,
        public readonly string $type,
        public readonly string $gatewayReference,
        public readonly array $payload,
    ) {
    }
}
