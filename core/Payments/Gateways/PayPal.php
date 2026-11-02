<?php

declare(strict_types=1);

namespace Core\Payments\Gateways;

use Core\Cache;
use Core\Payments\Checkout;
use Core\Payments\Gateway;
use Core\Payments\PaymentException;
use Core\Payments\PaymentRequest;
use Core\Payments\PaymentResult;
use Core\Payments\WebhookEvent;
use Core\Request;

/**
 * PayPal Orders API v2. The gateway reference is the PayPal order ID; verify() captures an
 * approved order and reports the final status.
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 * .env: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_WEBHOOK_ID, PAYPAL_MODE=sandbox|live
 */
final class PayPal extends Gateway
{
    public function name(): string
    {
        return 'paypal';
    }

    private function base(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** OAuth access token, cached until shortly before it expires. */
    private function accessToken(): string
    {
        $cacheKey = 'paypal.token.' . sha1($this->setting('client_id') . $this->base());
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http()->withBasicAuth($this->setting('client_id'), $this->setting('client_secret'))
            ->asForm()->post($this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->ok() || !$response->json('access_token')) {
            $this->fail($response, 'authenticate');
        }

        $token = (string) $response->json('access_token');
        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    public function initialize(PaymentRequest $request): Checkout
    {
        $callback = $request->callbackUrl ?? url('/');

        $response = $this->http()->withToken($this->accessToken())->asJson()
            ->withHeaders(['PayPal-Request-Id' => $request->reference])
            ->post($this->base() . '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $request->reference,
                    'custom_id' => $request->reference,
                    'description' => $request->description,
                    'amount' => ['currency_code' => $request->currency, 'value' => $request->amountMajor()],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => $callback,
                    'cancel_url' => $request->cancelUrl ?? $callback,
                    'user_action' => 'PAY_NOW',
                    'brand_name' => (string) config('app.name'),
                ]]],
            ]);

        if (!$response->ok()) {
            $this->fail($response, 'start the payment');
        }

        $approve = '';
        foreach ((array) $response->json('links', []) as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approve = (string) $link['href'];
            }
        }
        if ($approve === '') {
            throw new PaymentException('PayPal did not return an approval link.');
        }

        return new Checkout($this->name(), $approve, $request->reference, (string) $response->json('id'), (array) $response->json());
    }

    public function verify(string $gatewayReference): PaymentResult
    {
        $order = $this->http()->withToken($this->accessToken())->get($this->base() . '/v2/checkout/orders/' . rawurlencode($gatewayReference));
        if (!$order->ok()) {
            $this->fail($order, 'verify the payment');
        }

        $data = (array) $order->json();
        if (($data['status'] ?? '') === 'APPROVED') {
            $capture = $this->http()->withToken($this->accessToken())->asJson()
                ->withHeaders(['PayPal-Request-Id' => 'capture-' . $gatewayReference])
                ->post($this->base() . '/v2/checkout/orders/' . rawurlencode($gatewayReference) . '/capture');
            if ($capture->ok()) {
                $data = (array) $capture->json();
            }
        }

        $captureData = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        $amount = $captureData['amount'] ?? $data['purchase_units'][0]['amount'] ?? [];

        $status = match (true) {
            ($data['status'] ?? '') === 'COMPLETED' && ($captureData['status'] ?? 'COMPLETED') === 'COMPLETED' => PaymentResult::SUCCESS,
            in_array($data['status'] ?? '', ['VOIDED'], true) => PaymentResult::FAILED,
            default => PaymentResult::PENDING,
        };

        return new PaymentResult(
            $this->name(),
            $status,
            $gatewayReference,
            (string) ($data['purchase_units'][0]['reference_id'] ?? $data['purchase_units'][0]['custom_id'] ?? ''),
            isset($amount['value']) ? PaymentRequest::toMinor((string) $amount['value']) : null,
            isset($amount['currency_code']) ? (string) $amount['currency_code'] : null,
            (string) ($data['status'] ?? ''),
            $data,
        );
    }

    /** PayPal webhooks are verified by asking PayPal itself (verify-webhook-signature). */
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $response = $this->http()->withToken($this->accessToken())->asJson()->post($this->base() . '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO', ''),
            'cert_url' => (string) $request->header('PAYPAL-CERT-URL', ''),
            'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME', ''),
            'webhook_id' => $this->setting('webhook_id'),
            'webhook_event' => $payload,
        ]);

        if (!$response->ok() || $response->json('verification_status') !== 'SUCCESS') {
            return null;
        }

        $resource = (array) ($payload['resource'] ?? []);
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? '');

        return new WebhookEvent($this->name(), (string) ($payload['event_type'] ?? ''), $orderId, $payload);
    }
}
