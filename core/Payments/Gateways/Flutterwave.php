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
 * Flutterwave (Africa-wide) — cards, mobile money, bank, USSD. Standard checkout (v3).
 * Docs: https://developer.flutterwave.com/docs
 * .env: FLUTTERWAVE_PUBLIC_KEY, FLUTTERWAVE_SECRET_KEY, FLUTTERWAVE_WEBHOOK_HASH
 */
final class Flutterwave extends Gateway
{
    private const BASE = 'https://api.flutterwave.com/v3';

    public function name(): string
    {
        return 'flutterwave';
    }

    public function initialize(PaymentRequest $request): Checkout
    {
        $response = $this->http()->withToken($this->setting('secret_key'))->asJson()->post(self::BASE . '/payments', [
            'tx_ref' => $request->reference,
            'amount' => $request->amountMajor(),            // Flutterwave expects major units ("150.00")
            'currency' => $request->currency,
            'redirect_url' => $request->callbackUrl,
            'customer' => array_filter([
                'email' => $request->email,
                'name' => $request->customerName,
                'phonenumber' => $request->phone,
            ]),
            'customizations' => ['title' => (string) config('app.name'), 'description' => $request->description],
            'meta' => $request->metadata,
        ]);

        if (!$response->ok() || $response->json('status') !== 'success') {
            $this->fail($response, 'start the payment');
        }

        return new Checkout($this->name(), (string) $response->json('data.link'), $request->reference, $request->reference, (array) $response->json('data'));
    }

    public function verify(string $gatewayReference): PaymentResult
    {
        $response = $this->http()->withToken($this->setting('secret_key'))
            ->get(self::BASE . '/transactions/verify_by_reference', ['tx_ref' => $gatewayReference]);

        if (!$response->ok() || $response->json('status') !== 'success') {
            $this->fail($response, 'verify the payment');
        }

        $status = match ((string) $response->json('data.status')) {
            'successful' => PaymentResult::SUCCESS,
            'failed', 'cancelled' => PaymentResult::FAILED,
            default => PaymentResult::PENDING,
        };

        return new PaymentResult(
            $this->name(),
            $status,
            $gatewayReference,
            (string) $response->json('data.tx_ref'),
            PaymentRequest::toMinor(number_format((float) $response->json('data.amount', 0), 2, '.', '')),
            (string) $response->json('data.currency'),
            (string) $response->json('data.processor_response', ''),
            (array) $response->json('data'),
        );
    }

    /** Flutterwave sends your secret hash in the "verif-hash" header. */
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent
    {
        $hash = (string) $request->header('verif-hash', '');
        if ($hash === '' || !hash_equals($this->setting('webhook_hash'), $hash)) {
            return null;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $data = (array) ($payload['data'] ?? $payload);

        return new WebhookEvent($this->name(), (string) ($payload['event'] ?? $payload['event.type'] ?? ''), (string) ($data['tx_ref'] ?? $data['txRef'] ?? ''), $payload);
    }
}
