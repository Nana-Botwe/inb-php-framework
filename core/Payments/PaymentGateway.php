<?php

declare(strict_types=1);

namespace Core\Payments;

use Core\Request;

/**
 * Every payment gateway implements this, so you can switch providers by changing PAYMENT_GATEWAY in .env.
 *
 * Flow:  initialize() -> redirect the customer to $checkout->url -> they pay on the provider's page
 *        -> provider redirects back to your callback URL and/or calls your webhook
 *        -> verify() on YOUR server (never trust the redirect alone) -> deliver the order.
 */
interface PaymentGateway
{
    /** Short name, e.g. "paystack". */
    public function name(): string;

    /** Create a hosted checkout. Card details never touch your server (keeps you out of PCI scope). */
    public function initialize(PaymentRequest $request): Checkout;

    /** Ask the provider for the real status of a payment. */
    public function verify(string $gatewayReference): PaymentResult;

    /**
     * Validate a webhook call. Returns the event when the signature is genuine, or null
     * (respond 400 and ignore it) when it is forged.
     */
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent;
}
