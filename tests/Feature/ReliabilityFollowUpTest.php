<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment as PaymentRecord;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentService;
use Core\Config;
use Core\Database;
use Core\DB;
use Core\Event;
use Core\Http\Client;
use Core\Http\ClientResponse;
use Core\Mail\Mail;
use Core\Queue\Queue;
use Core\Session;
use Tests\TestCase;

/**
 * Second security review: webhook storage errors, durable fulfilment, background reset emails.
 */
final class ReliabilityFollowUpTest extends TestCase
{
    /** Controls the deliberately failing listener / model hook below (they stay registered for the process). */
    private static bool $listenerFails = false;

    private static int $listenerRuns = 0;

    private static bool $webhookStoreFails = false;

    private static bool $hooksRegistered = false;

    protected function setUp(): void
    {
        Config::set('payments.default', 'paystack');
        Config::set('payments.gateways.paystack.secret_key', 'sk_test_123');
        \Core\Payments\Payment::flush();

        self::$listenerFails = false;
        self::$listenerRuns = 0;
        self::$webhookStoreFails = false;

        if (!self::$hooksRegistered) {
            self::$hooksRegistered = true;
            Event::listen('payment.succeeded', static function (): void {
                self::$listenerRuns++;
                if (self::$listenerFails) {
                    throw new \RuntimeException('Warehouse API is down');
                }
            });
            PaymentWebhookEvent::creating(static function (): void {
                if (self::$webhookStoreFails) {
                    throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                }
            });
        }
    }

    public function tearDown(): void
    {
        self::$listenerFails = false;
        self::$webhookStoreFails = false;
    }

    // ================================================================== webhook storage errors

    public function test_a_database_error_while_storing_a_webhook_is_retried_not_acknowledged(): void
    {
        $payment = $this->pendingPayment(5000);
        $this->fakeVerify(5000);

        self::$webhookStoreFails = true;
        $this->postWebhook($payment->gateway_reference)->assertStatus(503);
        $this->assertSame(0, PaymentWebhookEvent::where('reference', $payment->gateway_reference)->count(), 'nothing was stored');
        $this->assertSame('pending', $payment->refresh()->status);

        // The provider retries once the database is back.
        self::$webhookStoreFails = false;
        $this->postWebhook($payment->gateway_reference)->assertOk();
        $this->assertSame('success', $payment->refresh()->status);
    }

    public function test_only_real_unique_violations_count_as_duplicates(): void
    {
        $make = static function (string $message, array $info): \PDOException {
            $e = new \PDOException($message);
            $e->errorInfo = $info;

            return $e;
        };

        $this->assertTrue(Database::isUniqueViolation($make("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'u'", ['23000', 1062, ''])));
        $this->assertTrue(Database::isUniqueViolation($make('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value', ['23505', 7, ''])));
        $this->assertTrue(Database::isUniqueViolation($make('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: t.c', ['23000', 19, ''])));
        $this->assertTrue(Database::isUniqueViolation($make('SQLSTATE[23000]: [Microsoft][ODBC Driver 18 for SQL Server]Violation of UNIQUE KEY constraint', ['23000', 2627, ''])));
        $this->assertTrue(Database::isUniqueViolation(new \RuntimeException('wrapped', 0, $make('ORA-00001: unique constraint violated', ['HY000', 1, '']))));

        $this->assertFalse(Database::isUniqueViolation($make('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: t.c', ['23000', 19, ''])));
        $this->assertFalse(Database::isUniqueViolation($make('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails', ['23000', 1452, ''])));
        $this->assertFalse(Database::isUniqueViolation($make('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, ''])));
        $this->assertFalse(Database::isUniqueViolation(new \RuntimeException('Duplicate entry')));
    }

    // ================================================================== durable fulfilment

    public function test_a_failing_fulfilment_listener_is_retried_until_it_succeeds(): void
    {
        $payment = $this->pendingPayment(5000);
        $this->fakeVerify(5000);
        $service = new PaymentService();

        self::$listenerFails = true;
        $this->assertSame(PaymentService::PAID, $service->confirm($payment), 'the customer DID pay');

        $row = DB::table('payments')->where('id', $payment->id)->first();
        $this->assertSame('success', $row['status']);
        $this->assertNull($row['fulfilled_at'], 'not fulfilled yet');
        $this->assertSame(1, (int) $row['fulfillment_attempts']);
        $this->assertStringContainsString('Warehouse API is down', (string) $row['fulfillment_error']);

        // Back-off: an immediate reconcile does not hammer the failing system.
        $this->assertSame(0, $service->reconcile()['fulfilled']);

        // The cause is fixed and the back-off has passed: reconcile completes the order.
        self::$listenerFails = false;
        $this->expireLease($payment);
        $this->assertSame(1, $service->reconcile()['fulfilled']);
        $this->assertNotNull(DB::table('payments')->where('id', $payment->id)->first()['fulfilled_at']);

        // And never again.
        $runs = self::$listenerRuns;
        $this->expireLease($payment);
        $service->reconcile();
        $this->assertSame($runs, self::$listenerRuns);
    }

    public function test_a_crash_after_marking_paid_is_fulfilled_by_reconcile(): void
    {
        // State left behind by a process that died right after the atomic "paid + lease" update.
        $payment = $this->pendingPayment(5000);
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'success',
            'paid_at' => date('Y-m-d H:i:s', time() - 900),
            'fulfillment_locked_until' => date('Y-m-d H:i:s', time() + 300),
        ]);

        $service = new PaymentService();
        $this->assertSame(0, $service->reconcile()['fulfilled'], 'the lease is respected while it may still be running');
        $this->assertSame(0, self::$listenerRuns);

        $this->expireLease($payment);
        $this->assertSame(1, $service->reconcile()['fulfilled']);
        $this->assertSame(1, self::$listenerRuns);
    }

    public function test_fulfilment_can_only_be_claimed_once(): void
    {
        $payment = $this->pendingPayment(5000);
        DB::table('payments')->where('id', $payment->id)->update(['status' => 'success', 'paid_at' => date('Y-m-d H:i:s')]);

        $service = new PaymentService();
        $this->assertTrue($service->fulfil(PaymentRecord::find($payment->id)));
        $this->assertFalse($service->fulfil(PaymentRecord::find($payment->id)));
        $this->assertSame(1, self::$listenerRuns);
    }

    // ================================================================== background reset emails

    public function test_reset_emails_are_queued_encrypted_when_a_queue_is_configured(): void
    {
        Config::set('queue.default', 'database');
        $this->createUser(['email' => 'queued@example.com']);

        $this->post('/forgot-password', ['email' => 'queued@example.com'])->assertRedirect();
        $this->assertCount(0, Mail::$sent, 'nothing is sent during the request');

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job);
        $this->assertStringNotContainsString('queued@example.com', (string) $job['payload'], 'the stored job is encrypted');
        $this->assertStringNotContainsString('reset-password', (string) $job['payload']);

        $this->assertTrue(Queue::work());
        $sent = Mail::sentTo('queued@example.com');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('reset-password/', quoted_printable_decode($sent[0]['raw']));
    }

    public function test_a_failing_mail_server_does_not_change_the_reset_answer(): void
    {
        $this->createUser(['email' => 'smtp@example.com']);
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'timeout' => 2]);

        $answers = [];
        foreach (['smtp@example.com', 'nobody-here@example.com'] as $email) {
            $_SESSION = [];
            $response = $this->post('/forgot-password', ['email' => $email]);
            $answers[] = [$response->status(), Session::getFlash('success'), Session::getFlash('errors')];
        }

        $this->assertSame($answers[0], $answers[1], 'an SMTP failure must look exactly like an unknown address');
        $this->assertSame(302, $answers[0][0]);
    }

    // ================================================================== helpers

    private function expireLease(PaymentRecord $payment): void
    {
        DB::table('payments')->where('id', $payment->id)->update(['fulfillment_locked_until' => date('Y-m-d H:i:s', time() - 1)]);
    }

    private function pendingPayment(int $amountMinor): PaymentRecord
    {
        $reference = 'PAY_' . bin2hex(random_bytes(6));
        $payment = new PaymentRecord(['description' => 'Test']);
        $payment->forceFill([
            'user_id' => (int) $this->createUser()->id,
            'gateway' => 'paystack',
            'reference' => $reference,
            'gateway_reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => 'GHS',
            'status' => 'pending',
        ])->save();

        return $payment;
    }

    private function fakeVerify(int $amountMinor): void
    {
        Client::fake(static function (array $request) use ($amountMinor): ClientResponse {
            preg_match('#verify/(.+)$#', $request['url'], $m);

            return new ClientResponse(200, (string) json_encode(['status' => true, 'data' => [
                'status' => 'success', 'reference' => $m[1] ?? '', 'amount' => $amountMinor, 'currency' => 'GHS',
            ]]));
        });
    }

    private function postWebhook(string $reference): \Core\Testing\TestResponse
    {
        $data = ['event' => 'charge.success', 'data' => ['reference' => $reference]];
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES);

        return $this->post('/payments/webhook/paystack', $data, ['X-Paystack-Signature' => hash_hmac('sha512', $body, 'sk_test_123')]);
    }
}
