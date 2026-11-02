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
 * Hubtel Online Checkout (Ghana) — MTN MoMo, Telecel Cash, AirtelTigo Money, cards.
 * Docs: https://developers.hubtel.com
 * .env: HUBTEL_CLIENT_ID, HUBTEL_CLIENT_SECRET, HUBTEL_MERCHANT_ACCOUNT
 *
 * Hubtel callbacks are not signed, so parseWebhook() only reads the reference and you MUST confirm
 * the payment with verify() (the status-check API) — the framework's PaymentController does this.
 * Hubtel may also require your server IP to be whitelisted for the status-check API.
 */
final class Hubtel extends Gateway
{
    private const CHECKOUT = 'https://payproxyapi.hubtel.com/items/initiate';

    private const STATUS = 'https://api-txnstatus.hubtel.com/transactions';

    public function name(): string
    {
        return 'hubtel';
    }

    public function initialize(PaymentRequest $request): Checkout
    {
        $callback = $request->callbackUrl ?? url('/');

        $response = $this->http()->withBasicAuth($this->setting('client_id'), $this->setting('client_secret'))->asJson()
            ->post(self::CHECKOUT, [
                'totalAmount' => (float) $request->amountMajor(),
                'description' => $request->description,
                // Server-to-server notification URL (your webhook route), separate from the browser return URL.
                'callbackUrl' => (string) ($this->config['callback_url'] ?? '')
                    ?: (\Core\App::instance()->router->has('payments.webhook') ? route('payments.webhook', ['gateway' => 'hubtel'], true) : $callback),
                'returnUrl' => $callback,
                'cancellationUrl' => $request->cancelUrl ?? $callback,
                'merchantAccountNumber' => $this->setting('merchant_account'),
                'clientReference' => substr($request->reference, 0, 32),
            ]);

        if (!$response->ok() || (string) $response->json('responseCode') !== '0000') {
            $this->fail($response, 'start the payment');
        }

        return new Checkout(
            $this->name(),
            (string) $response->json('data.checkoutUrl'),
            $request->reference,
            (string) $response->json('data.clientReference', substr($request->reference, 0, 32)),
            (array) $response->json('data'),
        );
    }

    public function verify(string $gatewayReference): PaymentResult
    {
        $response = $this->http()->withBasicAuth($this->setting('client_id'), $this->setting('client_secret'))
            ->get(self::STATUS . '/' . rawurlencode($this->setting('merchant_account')) . '/status', ['clientReference' => $gatewayReference]);

        if (!$response->ok()) {
            $this->fail($response, 'verify the payment');
        }

        $status = match (strtolower((string) $response->json('data.status'))) {
            'paid', 'success', 'successful' => PaymentResult::SUCCESS,
            'unpaid', 'failed', 'cancelled', 'refunded' => PaymentResult::FAILED,
            default => PaymentResult::PENDING,
        };

        $amount = $response->json('data.amount');

        return new PaymentResult(
            $this->name(),
            $status,
            $gatewayReference,
            (string) $response->json('data.clientReference', $gatewayReference),
            $amount !== null ? PaymentRequest::toMinor(number_format((float) $amount, 2, '.', '')) : null,
            (string) $response->json('data.currencyCode', 'GHS'),
            (string) $response->json('data.status', ''),
            (array) $response->json('data'),
        );
    }

    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $reference = (string) ($payload['Data']['ClientReference'] ?? $payload['data']['clientReference'] ?? '');

        return $reference === '' ? null : new WebhookEvent($this->name(), (string) ($payload['ResponseCode'] ?? ''), $reference, $payload);
    }
}
