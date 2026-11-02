<?php

declare(strict_types=1);

namespace Core\Payments;

/**
 * Verified status of a payment.
 *
 *   $result = Payment::verify($order->gateway_reference);
 *   if ($result->isSuccessful() && $result->matches($order->amount_minor, $order->currency)) { ...deliver... }
 *
 * ALWAYS check matches(): it stops a customer paying 1 pesewa for a 500 GHS order.
 */
final class PaymentResult
{
    public const SUCCESS = 'success';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $gateway,
        public readonly string $status,
        public readonly string $gatewayReference,
        public readonly ?string $reference,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly string $message = '',
        public readonly array $raw = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /** The paid amount and currency are exactly what you expected. */
    public function matches(int $expectedAmountMinor, string $expectedCurrency): bool
    {
        return $this->amountMinor === $expectedAmountMinor
            && $this->currency !== null
            && strtoupper($this->currency) === strtoupper($expectedCurrency);
    }

    public function amountMajor(): ?string
    {
        return $this->amountMinor === null ? null : PaymentRequest::toMajor($this->amountMinor);
    }
}
