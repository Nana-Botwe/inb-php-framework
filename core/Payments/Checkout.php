<?php

declare(strict_types=1);

namespace Core\Payments;

/**
 * Result of initialize(): send the customer to $url.
 * Save $reference AND $gatewayReference with your order — verify() needs $gatewayReference.
 */
final class Checkout
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $gateway,
        public readonly string $url,
        public readonly string $reference,
        public readonly string $gatewayReference,
        public readonly array $raw = [],
    ) {
    }
}
