<?php

declare(strict_types=1);

namespace Core\Payments;

use Core\Token;
use InvalidArgumentException;

/**
 * What the customer should pay. Amounts are kept in MINOR units (pesewas, kobo, cents) as integers,
 * so there are never floating-point rounding errors with money.
 *
 *   PaymentRequest::make(amount: '150.00', currency: 'GHS', email: $user->email)
 *       ->callbackUrl(route('payments.callback'))
 *       ->description('Order #1042');
 */
final class PaymentRequest
{
    public string $reference;

    public ?string $callbackUrl = null;

    public ?string $cancelUrl = null;

    public string $description = 'Payment';

    public ?string $customerName = null;

    public ?string $phone = null;

    /** @var array<string, scalar> */
    public array $metadata = [];

    private function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $email,
    ) {
        $this->reference = Token::reference('PAY');
    }

    /** @param string|int|float $amount Major units, e.g. "150.00" GHS */
    public static function make(string|int|float $amount, string $currency, string $email): self
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code such as GHS, NGN, USD.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid customer email is required.');
        }

        $minor = self::toMinor($amount);
        if ($minor <= 0) {
            throw new InvalidArgumentException('The amount must be greater than zero.');
        }

        return new self($minor, $currency, strtolower($email));
    }

    /** "150.5" -> 15050 without floating point. */
    public static function toMinor(string|int|float $amount): int
    {
        $text = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException("Invalid amount [$text].");
        }
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** 15050 -> "150.50" */
    public static function toMajor(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public function amountMajor(): string
    {
        return self::toMajor($this->amountMinor);
    }

    public function reference(string $reference): self
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{6,100}$/', $reference)) {
            throw new InvalidArgumentException('References may only contain letters, numbers, "_", "-" and ".".');
        }
        $this->reference = $reference;

        return $this;
    }

    public function callbackUrl(string $url): self
    {
        $this->callbackUrl = $url;

        return $this;
    }

    public function cancelUrl(string $url): self
    {
        $this->cancelUrl = $url;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = mb_substr(strip_tags($description), 0, 200);

        return $this;
    }

    public function customer(?string $name = null, ?string $phone = null): self
    {
        $this->customerName = $name;
        $this->phone = $phone;

        return $this;
    }

    /** @param array<string, scalar> $metadata */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
