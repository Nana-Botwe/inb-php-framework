<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment as PaymentRecord;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Core\Database;
use Core\DB;
use Core\Log;
use Core\Payments\Checkout;
use Core\Payments\Payment;
use Core\Payments\PaymentException;
use Core\Payments\PaymentRequest;
use Core\Payments\PaymentResult;
use Core\Request;
use Throwable;

/**
 * Payment orchestration — the only place that changes a payment's state.
 *
 * Guarantees:
 *  - state changes are ATOMIC: "UPDATE ... WHERE id = ? AND status = 'pending'" — when a callback and a
 *    webhook (or two webhooks) race, exactly one wins; only the winner fires "payment.succeeded"
 *  - a payment is marked paid only when the provider confirms it AND the amount/currency match
 *  - fulfilment is DURABLE: "paid" is saved together with a fulfilment lease; fulfilled_at is set only after
 *    every "payment.succeeded" listener succeeded. A crash or a throwing listener is retried by reconcile()
 *  - genuine webhooks are stored first and retried later if they can't be processed now; a webhook is only
 *    acknowledged (200) when it is safely stored
 *  - pending payments are re-checked by reconcile() (scheduled every 5 minutes)
 *
 * Delivery is AT LEAST once: listeners of "payment.succeeded" must be idempotent
 * (e.g. "deliver order if not delivered yet") because a retry can follow a partial run.
 */
final class PaymentService
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const ALREADY_FINAL = 'already_final';

    public const UNAVAILABLE = 'unavailable';   // provider could not be reached / temporary error

    /** Hours after which a payment that never completed is closed as failed. */
    private const EXPIRE_AFTER_HOURS = 48;

    /** How long one process may run the "payment.succeeded" listeners before another may take over. */
    private const FULFILMENT_LEASE_SECONDS = 600;

    /** After this many failed fulfilment attempts the payment is left for a human (logged as an alert). */
    private const MAX_FULFILMENT_ATTEMPTS = 10;

    public function start(User $user, string $amount, ?string $description, ?string $gateway = null): Checkout
    {
        $gateway ??= (string) config('payments.default');
        $currency = (string) config('payments.currency', 'GHS');

        $request = PaymentRequest::make($amount, $currency, (string) $user->email)
            ->description($description !== null && $description !== '' ? $description : 'Payment')
            ->customer((string) $user->name)
            ->callbackUrl(route('payments.callback', ['gateway' => $gateway], true))
            ->metadata(['user_id' => (int) $user->id]);

        $checkout = Payment::initialize($request, $gateway);

        $record = new PaymentRecord(['description' => $request->description]);
        $record->forceFill([
            'user_id' => $user->id,
            'gateway' => $gateway,
            'reference' => $checkout->reference,
            'gateway_reference' => $checkout->gatewayReference,
            'amount_minor' => $request->amountMinor,
            'currency' => $currency,
            'status' => 'pending',
        ])->save();

        return $checkout;
    }

    /** Ask the provider for the real status and apply it exactly once. Returns one of the constants. */
    public function confirm(PaymentRecord $payment): string
    {
        $payment->refresh();
        if ($payment->status !== 'pending') {
            return self::ALREADY_FINAL;
        }

        try {
            $result = Payment::verify((string) $payment->gateway_reference, (string) $payment->gateway);
        } catch (PaymentException $e) {
            report($e);

            return self::UNAVAILABLE;
        } finally {
            DB::table('payments')->where('id', $payment->id)->update(['last_checked_at' => date('Y-m-d H:i:s')]);
        }

        if ($result->isSuccessful() && $result->matches((int) $payment->amount_minor, (string) $payment->currency)) {
            // "paid" and "this process now owns fulfilment" are written in ONE atomic update. If we crash or a
            // listener fails before fulfilled_at is set, the lease expires and reconcile() runs it again.
            $won = $this->transition($payment, 'success', [
                'paid_at' => date('Y-m-d H:i:s'),
                'meta' => json_encode(['gateway_message' => $result->message]),
                'fulfillment_locked_until' => $this->leaseUntil(),
            ]);
            if ($won) {
                $this->runFulfilment($payment->refresh());   // failures are recorded and retried, never lost

                return self::PAID;
            }

            return self::ALREADY_FINAL;
        }

        if ($result->isSuccessful()) {
            // Paid, but not what we asked for: never deliver. Flag for review.
            $this->transition($payment, 'failed', ['meta' => json_encode(['reason' => 'amount_mismatch', 'paid' => $result->amountMinor, 'currency' => $result->currency])]);
            Log::security('payment_amount_mismatch', ['payment' => $payment->id, 'expected' => $payment->amount_minor, 'paid' => $result->amountMinor]);

            return self::FAILED;
        }

        if ($result->status === PaymentResult::FAILED) {
            $this->transition($payment, 'failed', ['meta' => json_encode(['gateway_message' => $result->message])]);

            return self::FAILED;
        }

        return self::PENDING;
    }

    /**
     * Webhook entry point. Returns: invalid | stored | processed | duplicate | retry
     *  - invalid    forged signature        -> HTTP 400
     *  - retry      temporary failure       -> HTTP 503 so the provider tries again (event is also stored)
     *  - others     handled or safely queued -> HTTP 200
     */
    public function handleWebhook(string $gateway, Request $request, string $rawBody): string
    {
        $event = Payment::parseWebhook($gateway, $request, $rawBody);
        if ($event === null) {
            Log::security('payment_webhook_rejected', ['gateway' => $gateway]);

            return 'invalid';
        }

        $hash = hash('sha256', $rawBody);
        $stored = PaymentWebhookEvent::where('gateway', $gateway)->where('payload_hash', $hash)->first();

        if ($stored !== null && $stored->status === 'processed') {
            return 'duplicate';
        }

        if ($stored === null) {
            $stored = (new PaymentWebhookEvent())->forceFill([
                'gateway' => $gateway,
                'event_type' => mb_substr($event->type, 0, 100),
                'reference' => mb_substr($event->gatewayReference, 0, 150),
                'payload_hash' => $hash,
                'payload' => $rawBody,
                'status' => 'received',
                'attempts' => 0,
            ]);
            try {
                $stored->save();
            } catch (Throwable $e) {
                if (!Database::isUniqueViolation($e)) {
                    // Database down, disk full, bad schema...: nothing was stored, so the provider MUST retry.
                    report($e);
                    Log::error('payment_webhook_store_failed', ['gateway' => $gateway, 'error' => $e->getMessage()]);

                    return 'retry';
                }
                // Same webhook arriving twice at the same moment: the unique index rejected the copy.
                // Answer 200 only when the original is really there.
                return PaymentWebhookEvent::where('gateway', $gateway)->where('payload_hash', $hash)->first() !== null
                    ? 'duplicate'
                    : 'retry';
            }
        }

        try {
            return $this->processEvent($stored);
        } catch (Throwable $e) {
            // The event is stored: reconcile() will retry it, and so will the provider after a 503.
            report($e);

            return 'retry';
        }
    }

    /** Try to apply a stored webhook event. */
    public function processEvent(PaymentWebhookEvent $event): string
    {
        $reference = (string) $event->reference;
        $payment = $reference === '' ? null : PaymentRecord::where('gateway', $event->gateway)
            ->where(fn ($q) => $q->where('gateway_reference', $reference)->orWhere('reference', $reference))
            ->first();

        $attempts = (int) $event->attempts + 1;

        if ($payment === null) {
            // The webhook may have beaten our own database write: keep it, reconcile() retries.
            $this->markEvent($event, 'unmatched', $attempts, 'No local payment with this reference yet.');

            return 'stored';
        }

        $outcome = $this->confirm($payment);

        if ($outcome === self::UNAVAILABLE) {
            $this->markEvent($event, 'failed', $attempts, 'Provider verification failed temporarily.');

            return 'retry';
        }

        $this->markEvent($event, 'processed', $attempts, null);

        return 'processed';
    }

    /**
     * Background safety net (scheduled every 5 minutes):
     *  1. retry stored webhook events that could not be processed
     *  2. re-verify pending payments with the provider
     *  3. finish fulfilment of paid payments whose listeners failed or never ran
     *  4. close payments that never completed within 48 hours
     *
     * @return array{events: int, checked: int, paid: int, fulfilled: int, expired: int}
     */
    public function reconcile(): array
    {
        $stats = ['events' => 0, 'checked' => 0, 'paid' => 0, 'fulfilled' => 0, 'expired' => 0];
        $threeDaysAgo = date('Y-m-d H:i:s', time() - 3 * 86400);

        $events = PaymentWebhookEvent::whereIn('status', ['unmatched', 'failed', 'received'])
            ->where('attempts', '<', 20)
            ->where('created_at', '>=', $threeDaysAgo)
            ->orderBy('id')->limit(100)->get();
        foreach ($events as $event) {
            $this->processEvent($event);
            $stats['events']++;
        }

        $pending = PaymentRecord::where('status', 'pending')
            ->where('created_at', '<=', date('Y-m-d H:i:s', time() - 120))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', date('Y-m-d H:i:s', time() - 600)))
            ->orderBy('id')->limit(100)->get();
        foreach ($pending as $payment) {
            $stats['checked']++;
            if ($this->confirm($payment) === self::PAID) {
                $stats['paid']++;
            }
        }

        // Paid but not (completely) fulfilled: a crashed request, a failing listener, or an expired lease.
        $unfulfilled = PaymentRecord::where('status', 'success')
            ->whereNull('fulfilled_at')
            ->where('fulfillment_attempts', '<', self::MAX_FULFILMENT_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('fulfillment_locked_until')->orWhere('fulfillment_locked_until', '<', date('Y-m-d H:i:s')))
            ->orderBy('id')->limit(100)->get();
        foreach ($unfulfilled as $payment) {
            if ($this->fulfil($payment)) {
                $stats['fulfilled']++;
            }
        }

        $stats['expired'] = DB::table('payments')
            ->where('status', 'pending')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::EXPIRE_AFTER_HOURS * 3600))
            ->update(['status' => 'failed', 'meta' => json_encode(['reason' => 'expired']), 'updated_at' => date('Y-m-d H:i:s')]);

        if (array_sum($stats) > 0) {
            Log::info('payments_reconciled', $stats);
        }

        return $stats;
    }

    /**
     * Run fulfilment for a paid payment if nobody else is running it. True when the listeners completed.
     * Safe to call any number of times, from any number of processes.
     */
    public function fulfil(PaymentRecord $payment): bool
    {
        $now = date('Y-m-d H:i:s');
        $claimed = DB::table('payments')
            ->where('id', $payment->id)
            ->where('status', 'success')
            ->whereNull('fulfilled_at')
            ->where('fulfillment_attempts', '<', self::MAX_FULFILMENT_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('fulfillment_locked_until')->orWhere('fulfillment_locked_until', '<', $now))
            ->update(['fulfillment_locked_until' => $this->leaseUntil(), 'updated_at' => $now]) === 1;

        return $claimed && $this->runFulfilment($payment->refresh());
    }

    /** The caller must hold the lease. Records success, or the error and when to try again. */
    private function runFulfilment(PaymentRecord $payment): bool
    {
        $attempts = (int) $payment->fulfillment_attempts + 1;

        try {
            event('payment.succeeded', [$payment]);
        } catch (Throwable $e) {
            report($e);
            $gaveUp = $attempts >= self::MAX_FULFILMENT_ATTEMPTS;
            DB::table('payments')->where('id', $payment->id)->update([
                'fulfillment_attempts' => $attempts,
                'fulfillment_error' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 1000),
                // Exponential back-off: 1, 2, 4 ... minutes (max 1 hour) before reconcile() tries again.
                'fulfillment_locked_until' => date('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** ($attempts - 1))),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Log::error($gaveUp ? 'payment_fulfillment_gave_up' : 'payment_fulfillment_failed', [
                'payment' => $payment->id, 'attempt' => $attempts, 'error' => $e->getMessage(),
            ]);
            if ($gaveUp) {
                // A customer paid and did not get what they paid for: alert a human (LOG_ALERT_EMAIL).
                report(new \RuntimeException("Payment #{$payment->id} was paid but fulfilment failed {$attempts} times. Fix the cause, then run: php console payments:fulfil {$payment->id}", 0, $e));
            }

            return false;
        }

        DB::table('payments')->where('id', $payment->id)->update([
            'fulfilled_at' => date('Y-m-d H:i:s'),
            'fulfillment_attempts' => $attempts,
            'fulfillment_error' => null,
            'fulfillment_locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    private function leaseUntil(): string
    {
        return date('Y-m-d H:i:s', time() + self::FULFILMENT_LEASE_SECONDS);
    }

    /** Atomic "pending -> $status". True only for the caller that made the change. */
    private function transition(PaymentRecord $payment, string $status, array $extra): bool
    {
        return DB::table('payments')
            ->where('id', $payment->id)
            ->where('status', 'pending')
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')] + $extra) === 1;
    }

    private function markEvent(PaymentWebhookEvent $event, string $status, int $attempts, ?string $error): void
    {
        $event->forceFill([
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => $error,
            'processed_at' => $status === 'processed' ? date('Y-m-d H:i:s') : null,
        ])->save();
    }
}
