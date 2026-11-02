<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment as PaymentRecord;
use Core\Config;
use Core\Http\Client;
use Core\Http\ClientResponse;
use Core\Payments\Payment;
use Core\Payments\PaymentRequest;
use Core\Sms\Gateways\LogGateway;
use Core\Sms\Sms;
use Core\Storage;
use Tests\TestCase;

/**
 * Payments and SMS are tested against a FAKE HTTP client, so no real money or messages are involved.
 */
final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set('payments.default', 'paystack');
        Config::set('payments.gateways.paystack.secret_key', 'sk_test_123');
        Config::set('payments.gateways.stripe.webhook_secret', 'whsec_test');
        Config::set('payments.gateways.stripe.secret_key', 'sk_test_stripe');
        Payment::flush();
    }

    public function tearDown(): void
    {
        Client::stopFaking();
    }

    public function test_money_is_handled_in_minor_units_without_floats(): void
    {
        $this->assertSame(15050, PaymentRequest::toMinor('150.5'));
        $this->assertSame(1999, PaymentRequest::toMinor(19.99));
        $this->assertSame('0.07', PaymentRequest::toMajor(7));
        $this->assertThrows(fn () => PaymentRequest::toMinor('1e3'), \InvalidArgumentException::class);
    }

    public function test_full_paystack_flow_with_amount_check(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Client::fake(static function (array $request): ClientResponse {
            if (str_contains($request['url'], '/transaction/initialize')) {
                $body = json_decode((string) $request['body'], true);

                return new ClientResponse(200, json_encode(['status' => true, 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => $body['reference'],
                ]]));
            }
            // verify: report the right amount
            preg_match('#verify/(.+)$#', $request['url'], $m);

            return new ClientResponse(200, json_encode(['status' => true, 'data' => [
                'status' => 'success', 'reference' => $m[1], 'amount' => 5000, 'currency' => 'GHS', 'gateway_response' => 'Approved',
            ]]));
        });

        $this->post('/payments', ['amount' => '50.00', 'description' => 'Course fee'])->assertRedirect();
        $this->assertSame('Bearer sk_test_123', Client::$recorded[0]['headers']['Authorization']);

        $record = PaymentRecord::where('user_id', $user->id)->first();
        $this->assertSame(5000, $record->amount_minor);
        $this->assertSame('pending', $record->status);

        $this->get('/payments/callback/paystack?reference=' . $record->reference)->assertOk()->assertSee('Payment successful');
        $this->assertSame('success', $record->refresh()->status);
    }

    public function test_underpaid_payments_are_never_marked_paid(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Client::fake(static function (array $request): ClientResponse {
            if (str_contains($request['url'], '/transaction/initialize')) {
                $body = json_decode((string) $request['body'], true);

                return new ClientResponse(200, json_encode(['status' => true, 'data' => ['authorization_url' => 'https://x', 'reference' => $body['reference']]]));
            }
            preg_match('#verify/(.+)$#', $request['url'], $m);

            return new ClientResponse(200, json_encode(['status' => true, 'data' => ['status' => 'success', 'reference' => $m[1], 'amount' => 1, 'currency' => 'GHS']]));
        });

        $this->post('/payments', ['amount' => '500.00'])->assertRedirect();
        $record = PaymentRecord::where('user_id', $user->id)->first();
        $this->get('/payments/callback/paystack?reference=' . $record->reference)->assertOk();
        $this->assertSame('failed', $record->refresh()->status);
    }

    public function test_webhooks_with_forged_signatures_are_rejected(): void
    {
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'X']]);
        $this->post('/payments/webhook/paystack', json_decode($body, true), ['X-Paystack-Signature' => 'forged'])->assertStatus(400);

        $valid = hash_hmac('sha512', $body, 'sk_test_123');
        Client::fake(static fn () => new ClientResponse(200, '{"status":true,"data":{"status":"pending"}}'));
        $this->post('/payments/webhook/paystack', json_decode($body, true), ['X-Paystack-Signature' => $valid])->assertOk();
    }

    public function test_stripe_webhook_signature_and_replay_protection(): void
    {
        $gateway = Payment::gateway('stripe');
        $body = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_test_1"}}}';
        $request = fn (string $sig) => new \Core\Request([], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'REQUEST_METHOD' => 'POST']);

        $t = time();
        $good = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_test');
        $this->assertSame('cs_test_1', $gateway->parseWebhook($request($good), $body)?->gatewayReference);

        $old = time() - 3600;
        $replayed = 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, 'whsec_test');
        $this->assertNull($gateway->parseWebhook($request($replayed), $body));
        $this->assertNull($gateway->parseWebhook($request('t=' . $t . ',v1=forged'), $body));
    }

    public function test_all_five_payment_gateways_are_available(): void
    {
        foreach (['paystack', 'flutterwave', 'stripe', 'paypal', 'hubtel'] as $name) {
            $this->assertSame($name, Payment::gateway($name)->name());
        }
    }

    public function test_sms_numbers_are_normalised_and_rate_limited(): void
    {
        LogGateway::$sent = [];
        $this->assertSame('+233241234567', Sms::normalize('024 123 4567'));
        $this->assertSame('+233241234567', Sms::normalize('233241234567'));
        $this->assertThrows(fn () => Sms::normalize('12'), \Core\Sms\SmsException::class);

        Sms::send('0241234567', 'Your code is 123456');
        $this->assertSame(['+233241234567'], LogGateway::$sent[0]['to']);

        for ($i = 0; $i < 12; $i++) {
            try {
                Sms::send('0201112222', 'spam ' . $i);
            } catch (\Core\Sms\SmsException) {
            }
        }
        $toThatNumber = array_filter(LogGateway::$sent, static fn ($m) => $m['to'] === ['+233201112222']);
        $this->assertCount(10, $toThatNumber);
    }

    public function test_sms_gateways_send_the_right_requests(): void
    {
        Config::set('sms.gateways.arkesel.api_key', 'ark_key');
        Config::set('sms.gateways.arkesel.sender', 'INBFIRM');
        Sms::flush();
        Client::fake(static fn () => new ClientResponse(200, '{"status":"success","data":[{"recipient":"233241234567","id":"m1"}]}'));

        $result = Sms::send('0241234567', 'Hello', null, 'arkesel');

        $this->assertTrue($result->successful);
        $this->assertSame('ark_key', Client::$recorded[0]['headers']['api-key']);
        $this->assertSame(['233241234567'], json_decode((string) Client::$recorded[0]['body'], true)['recipients']);

        foreach (['twilio', 'africastalking', 'hubtel', 'arkesel'] as $name) {
            $this->assertSame($name, Sms::gateway($name)->name());
        }
    }

    public function test_uploaded_images_are_compressed_and_safe(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->post('/profile/avatar', [], [], ['avatar' => $this->fakeImage('me.jpg', 3000, 2000)])->assertRedirect();

        $path = (string) $user->refresh()->avatar;
        $this->assertTrue(str_ends_with($path, '.webp'), "Expected a WebP file, got $path");
        $stored = Storage::disk('public')->path($path);
        [$width] = getimagesize($stored);
        $this->assertSame(1920, $width);
        Storage::disk('public')->delete($path);
    }

    public function test_php_disguised_as_an_image_is_rejected(): void
    {
        $this->actingAs($this->createUser());
        $this->post('/profile/avatar', [], [], ['avatar' => $this->fakeUpload('shell.php.jpg', '<?php system($_GET["c"]); ?>')])
            ->assertSessionHasErrors(['avatar']);
    }

    public function test_documents_are_compressed_losslessly(): void
    {
        $text = str_repeat("Invoice line: INB IT Firm, Accra, Ghana — 100.00 GHS\n", 2000);
        $file = new \Core\Http\UploadedFile($this->fakeUpload('report.txt', $text), true);

        $path = $file->store('reports');
        $this->assertTrue(str_ends_with($path, '.gz') || str_ends_with($path, '.br') || str_ends_with($path, '.zst'));
        $this->assertTrue(Storage::disk()->size($path) < strlen($text) / 10, 'Expected at least 10x smaller.');
        $this->assertSame($text, Storage::disk()->read($path));
        Storage::disk()->delete($path);
    }
}
