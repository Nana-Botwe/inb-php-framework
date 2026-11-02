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
 * Stripe Checkout (global cards, wallets). The gateway reference is the Checkout Session ID.
 * Docs: https://docs.stripe.com/api/checkout/sessions
 * .env: STRIPE_PUBLIC_KEY, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
 */
final class Stripe extends Gateway
{
    private const BASE = 'https://api.stripe.com/v1';

    /** Currencies Stripe treats as having no minor unit. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function name(): string
    {
        return 'stripe';
    }

    public function initialize(PaymentRequest $request): Checkout
    {
        $amount = in_array($request->currency, self::ZERO_DECIMAL, true) ? intdiv($request->amountMinor, 100) : $request->amountMinor;
        $callback = $request->callbackUrl ?? url('/');

        $data = [
            'mode' => 'payment',
            'success_url' => $callback . (str_contains($callback, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->cancelUrl ?? $callback,
            'customer_email' => $request->email,
            'client_reference_id' => $request->reference,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->currency),
                    'unit_amount' => $amount,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            'metadata' => ['reference' => $request->reference] + $request->metadata,
        ];

        $response = $this->http()->withToken($this->setting('secret_key'))->asForm()
            ->withHeaders(['Idempotency-Key' => $request->reference])
            ->post(self::BASE . '/checkout/sessions', $data);

        if (!$response->ok() || !$response->json('url')) {
            $this->fail($response, 'start the payment');
        }

        return new Checkout($this->name(), (string) $response->json('url'), $request->reference, (string) $response->json('id'), (array) $response->json());
    }

    public function verify(string $gatewayReference): PaymentResult
    {
        $response = $this->http()->withToken($this->setting('secret_key'))
            ->get(self::BASE . '/checkout/sessions/' . rawurlencode($gatewayReference));

        if (!$response->ok()) {
            $this->fail($response, 'verify the payment');
        }

        $status = match (true) {
            $response->json('payment_status') === 'paid' => PaymentResult::SUCCESS,
            $response->json('status') === 'expired' => PaymentResult::FAILED,
            default => PaymentResult::PENDING,
        };

        $currency = strtoupper((string) $response->json('currency'));
        $amount = (int) $response->json('amount_total');

        return new PaymentResult(
            $this->name(),
            $status,
            $gatewayReference,
            (string) $response->json('client_reference_id'),
            in_array($currency, self::ZERO_DECIMAL, true) ? $amount * 100 : $amount,
            $currency,
            (string) $response->json('payment_status'),
            (array) $response->json(),
        );
    }

    /**
     * Stripe-Signature: "t=TIMESTAMP,v1=HMAC_SHA256(t.body)". Rejects events older than 5 minutes (replay attacks).
     */
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent
    {
        $header = (string) $request->header('Stripe-Signature', '');
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > (int) ($this->config['tolerance'] ?? 300)) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->setting('webhook_secret'));
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = $valid || hash_equals($expected, $signature);
        }

        $payload = json_decode($rawBody, true);
        if (!$valid || !is_array($payload)) {
            return null;
        }

        return new WebhookEvent($this->name(), (string) ($payload['type'] ?? ''), (string) ($payload['data']['object']['id'] ?? ''), $payload);
    }
}
