# Payment gateways

Five gateways share one interface, so switching providers is a one-line change.

| Gateway | Countries / methods | Key |
|---|---|---|
| **Paystack** | Ghana, Nigeria, Kenya, South Africa — cards, mobile money, bank | `paystack` |
| **Flutterwave** | across Africa — cards, mobile money, bank, USSD | `flutterwave` |
| **Stripe** | global — cards, Apple/Google Pay | `stripe` |
| **PayPal** | global — PayPal balance, cards | `paypal` |
| **Hubtel** | Ghana — MTN MoMo, Telecel Cash, AirtelTigo Money, cards | `hubtel` |

## Install a gateway

```bash
php console payment:install paystack
php console payment:list              # which gateways are configured
```

`payment:install` adds the credential keys to `.env` (and `.env.example`), sets `PAYMENT_GATEWAY`, and prints the webhook URL to register with the provider. Then fill in your keys:

| Gateway | .env keys |
|---|---|
| Paystack | `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` |
| Flutterwave | `FLUTTERWAVE_PUBLIC_KEY`, `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_WEBHOOK_HASH` |
| Stripe | `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` |
| PayPal | `PAYPAL_MODE` (sandbox/live), `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID` |
| Hubtel | `HUBTEL_CLIENT_ID`, `HUBTEL_CLIENT_SECRET`, `HUBTEL_MERCHANT_ACCOUNT` |

```ini
PAYMENT_GATEWAY=paystack
PAYMENT_CURRENCY=GHS
```

> **Security:** Use TEST keys while developing. Secret keys stay in `.env` — never in JavaScript, views or Git. Customers pay on the provider's hosted page, so card details never touch your server (this keeps you out of most PCI-DSS scope).

## The ready-made flow

Signed-in, verified users can open **`/payments`**, enter an amount and pay. `PaymentController` is a thin HTTP layer; every decision lives in **`App\Services\PaymentService`**:

1. **checkout** — `start()` creates a `pending` row (amount in minor units) and redirects to the provider.
2. **callback** — when the customer returns, `confirm()` **verifies with the provider's API** (the redirect alone is never trusted).
3. **webhook** — `POST /payments/webhook/{gateway}` checks the **signature**, **stores the event**, then confirms with the API.
4. A payment becomes `success` **only if the paid amount and currency exactly match**. Underpayments become `failed` and are logged as a security event.

## Reliability

**Exactly-once state changes.** Every change is one atomic statement:

```sql
UPDATE payments SET status = 'success', paid_at = ... WHERE id = ? AND status = 'pending'
```

When a callback and a webhook — or two webhooks — arrive at the same moment, only one UPDATE can match the pending row. Only that request runs the `payment.succeeded` listeners.

**Fulfilment is never lost.** The same atomic UPDATE also gives the winning request a 10-minute *fulfilment lease*. `fulfilled_at` is set only after **every** `payment.succeeded` listener has finished:

| What goes wrong after the payment is marked paid | What happens |
|---|---|
| a listener throws (warehouse API down, mail server down…) | the error is saved in `fulfillment_error`; retried with back-off 1, 2, 4… minutes (max 1 h) |
| the PHP process dies (timeout, server restart) | the lease expires; the next reconciliation runs the listeners |
| 10 attempts fail | an alert is emailed (`LOG_ALERT_EMAIL`); fix the cause, then `php console payments:fulfil <id>` |

The customer still sees "payment successful" — they did pay — while delivery is retried in the background. `php console payments:fulfil` lists every paid payment still waiting.

> **Warning:** Delivery is *at least once*: after a partial run the listeners are called again. Make every `payment.succeeded` listener idempotent, e.g. `Order::where('payment_id', $p->id)->whereNull('delivered_at')->update([...])` instead of "send the goods" unconditionally.

**Webhooks are never lost.** Every genuine webhook is saved in `payment_webhook_events` before anything else, and the provider gets 200 **only once it is safely stored**:

| Situation | Response | What happens next |
|---|---|---|
| forged signature | 400 | logged, ignored |
| processed | 200 | done |
| the provider's API is down while we verify | **503** + `Retry-After` | the provider retries, and our reconciliation retries too |
| our database fails while storing it (outage, disk full…) | **503** | nothing was stored, so the provider must retry; the error is reported |
| webhook arrives before our payment row exists | 200 | stored as `unmatched`, matched by reconciliation |
| same webhook again (also two copies at the same instant) | 200 | ignored — only a real unique-key violation counts as a duplicate |

**Reconciliation.** `php console payments:reconcile` (every 5 minutes) retries stored events, re-verifies pending payments with the provider, finishes interrupted fulfilment and closes payments still pending after 48 hours. Customers whose provider confirmation was delayed still get their order.

## Using it in your own code

```php
use Core\Payments\Payment;
use Core\Payments\PaymentRequest;

$request = PaymentRequest::make('150.00', 'GHS', $user->email)    // amounts as strings: no float errors
    ->description('Course fee — Web Development')
    ->customer($user->name, $user->phone)
    ->callbackUrl(route('payments.callback', ['gateway' => 'paystack'], true))
    ->metadata(['order_id' => $order->id]);

$checkout = Payment::initialize($request);         // default gateway
// $checkout = Payment::gateway('stripe')->initialize($request);

$order->forceFill([
    'reference' => $checkout->reference,
    'gateway_reference' => $checkout->gatewayReference,   // needed to verify later
])->save();

return redirect($checkout->url);
```

Verify:

```php
$result = Payment::verify($order->gateway_reference, 'paystack');

if ($result->isSuccessful() && $result->matches($order->amount_minor, 'GHS')) {
    // deliver the order
}
$result->isPending(); $result->isFailed();
$result->amountMajor();   // "150.00"
```

> **Danger:** Always call `matches()`. It stops a customer from paying 1 pesewa for a 500 GHS order by tampering with the checkout.

## Money

Amounts are stored as integers in the smallest unit (pesewas, kobo, cents):

```php
PaymentRequest::toMinor('150.50');   // 15050
PaymentRequest::toMajor(15050);      // "150.50"
```

Never use floats for money.

## Webhooks

Register `https://your-site.com/payments/webhook/{gateway}` in the provider's dashboard.

| Gateway | How the webhook is verified |
|---|---|
| Paystack | HMAC-SHA512 of the body with your secret key (`x-paystack-signature`) |
| Flutterwave | your secret hash in the `verif-hash` header |
| Stripe | HMAC-SHA256 `Stripe-Signature`, rejected if older than 5 minutes (replay protection) |
| PayPal | verified by PayPal's own verify-webhook-signature API |
| Hubtel | callbacks aren't signed, so the payment is confirmed with Hubtel's status-check API |

All comparisons are constant-time. Forged webhooks get HTTP 400 and are logged.

> **Note:** Hubtel's status-check API may require your server's IP to be whitelisted with Hubtel. Provider APIs change over time — check each provider's current documentation when you go live.

## Adding your own gateway

```php
final class MyBank extends Core\Payments\Gateway
{
    public function name(): string { return 'mybank'; }
    public function initialize(PaymentRequest $request): Checkout { ... }
    public function verify(string $gatewayReference): PaymentResult { ... }
    public function parseWebhook(Request $request, string $rawBody): ?WebhookEvent { ... }
}

// config/payments.php
'mybank' => ['driver' => App\Payments\MyBank::class, 'secret_key' => env('MYBANK_SECRET')],
```

## Testing payments without real money

```php
Client::fake(fn (array $request) => new ClientResponse(200, '{"status":true,"data":{"status":"success","amount":5000,"currency":"GHS"}}'));
```

See [Testing](testing).
