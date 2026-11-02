<?php

declare(strict_types=1);

namespace Core\Payments\Gateways;

use Core\Payments\Checkout;
use Core\Payments\Gateway;
use Core\Payments\PaymentRequest;
use Core\Payments\PaymentResult;
use Core\Payments\WebhookEvent;
use Core\Request;

/**
 * Paystack (Ghana, Nigeria, Kenya, South Africa) — cards, mobile money, bank.
 * Docs: https://paystack.com/docs/api/
 * .env: PAYSTACK_PUBLIC_KEY, PAYSTACK_SECRET_KEY
 */
final class Paystack extends Gateway
{
    private const BASE = 'https://api.paystack.co';

    public function name(): string
    {
        return 'paystack';
    }

    public function initialize(PaymentRequest $request): Checkout
    {
        $response = $this->http()->withToken($this->setting('secret_key'))->asJson()->post(self::BASE . '/transaction/initialize', array_filter([
            'email' => $request->email,
            'amount' => $request->amountMinor,           // Paystack expects the lowest unit (pesewas/kobo)
            'currency' => $request->currency,
            'reference' => $request->reference,
            'callback_url' => $request->callbackUrl,
            'metadata' => $request->metadata + ['description' => $request->description],
        ], static fn ($v) => $v !== null));

        if (!$response->ok() || $response->json('status') !== true) {
            $this->fail($response, 'start the payment');
        }

        return new Checkout($this->name(), (string) $response->json('data.authorization_url'), $request->reference, $request->reference, (array) $response->json('data'));
    }

    public function verify(string $gatewayReference): PaymentResult
    {
        $response = $this->http()->withToken($this->setting('secret_key'))
            ->get(self::BASE . '/transaction/verify/' . rawurlencode($gatewayReference));

        if (!$response->ok() || $response->json('status') !== true) {
            $this->fail($response, 'verify the payment');
        }

        $status = match ((string) $response->json('data.status')) {
            'success' => PaymentResult::SUCCESS,
            'failed', 'abandoned', 'reversed' => PaymentResult::FAILED,
            default => PaymentResult::PENDING,
        };

        return new PaymentResult(
            $this->name(),
            $status,
            $gatewayReference,
            (string) $response->json('data.reference'),
            (int) $response->json('data.amount'),
            (string) $response->json('data.currency'),
            (string) $response->json('data.gateway_response', ''),
            (array) $response->json('data'),
        );
    }

    /** Paystack signs the raw body with HMAC-SHA512 using your secret key (header x-paystack-signature). */
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent
    {
        $signature = (string) $request->header('X-Paystack-Signature', '');
        $expected = hash_hmac('sha512', $rawBody, $this->setting('secret_key'));

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        return new WebhookEvent($this->name(), (string) ($payload['event'] ?? ''), (string) ($payload['data']['reference'] ?? ''), $payload);
    }
}
