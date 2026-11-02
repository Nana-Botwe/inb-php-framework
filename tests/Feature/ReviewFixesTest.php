<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment as PaymentRecord;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Services\PaymentService;
use Core\Auth;
use Core\Auth\Password;
use Core\Cache;
use Core\Config;
use Core\Crypt;
use Core\DB;
use Core\Event;
use Core\Http\Client;
use Core\Http\ClientResponse;
use Core\Mail\Mail;
use Core\RateLimiter;
use Core\Request;
use Core\Session;
use Core\Token;
use Core\URL;
use Tests\TestCase;

/**
 * One test (or more) per finding of the security review.
 */
final class ReviewFixesTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set('payments.default', 'paystack');
        Config::set('payments.gateways.paystack.secret_key', 'sk_test_123');
        \Core\Payments\Payment::flush();
    }

    // ================================================================== HIGH: credential revocation

    public function test_password_change_revokes_remember_me_api_tokens_and_other_sessions(): void
    {
        $user = $this->createUser(['email' => 'rev@example.com', 'password' => 'Old#Pass123']);

        // Device A: logs in with "remember me".
        $this->post('/login', ['email' => 'rev@example.com', 'password' => 'Old#Pass123', 'remember' => '1'])->assertRedirect('/dashboard');
        $rememberCookie = $this->cookieValue('remember_web');
        $this->assertNotNull($rememberCookie, 'remember cookie should be set');
        $deviceA = $_SESSION;

        // An API token exists too.
        $plain = $user->createToken('phone')->plainTextToken;

        // Device B changes the password.
        $_SESSION = [];
        Auth::flush();
        $this->actingAs($user->refresh());
        $this->put('/profile/password', ['current_password' => 'Old#Pass123', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])->assertRedirect();
        $this->get('/dashboard')->assertOk(); // device B stays signed in

        // Device A's session is dead.
        $_SESSION = $deviceA;
        Auth::flush();
        $this->setCookie('remember_web', null);
        $this->get('/dashboard')->assertRedirect('/login');

        // Device A's old remember-me cookie can NOT start a new session.
        $_SESSION = [];
        Auth::flush();
        $this->setCookie('remember_web', $rememberCookie);
        $this->get('/dashboard')->assertRedirect('/login');

        // The API token was revoked.
        $this->setCookie('remember_web', null);
        $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $plain])->assertUnauthorized();
        $this->assertNull($user->refresh()->getAttributes()['remember_token']);
    }

    public function test_password_reset_revokes_every_credential_and_tokens_are_single_use(): void
    {
        $user = $this->createUser(['email' => 'reset@example.com', 'password' => 'Old#Pass123']);
        $plain = $user->createToken('phone')->plainTextToken;
        $user->forceFill(['remember_token' => Token::hash('old-remember')])->save();

        $this->post('/forgot-password', ['email' => 'reset@example.com']);
        preg_match('#reset-password/([A-Za-z0-9]+)#', quoted_printable_decode(Mail::sentTo('reset@example.com')[0]['raw']), $m);
        $token = $m[1];

        $this->assertSame(Password::PASSWORD_RESET, Password::reset('reset@example.com', $token, 'New#Pass123'));
        // Second use of the same token fails (it was consumed atomically).
        $this->assertSame(Password::INVALID_TOKEN, Password::reset('reset@example.com', $token, 'Other#Pass123'));

        $fresh = $user->refresh();
        $this->assertTrue(password_verify('New#Pass123', $fresh->getAttributes()['password']));
        $this->assertNull($fresh->getAttributes()['remember_token']);
        $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $plain])->assertUnauthorized();
    }

    public function test_sign_out_other_devices(): void
    {
        $user = $this->createUser(['password' => 'Pass#1234']);
        $plain = $user->createToken('cli')->plainTextToken;
        $this->actingAs($user);
        \Core\Session::set(\Core\Middleware\RequirePassword::SESSION_KEY, time());

        $this->post('/profile/logout-other-devices')->assertRedirect();
        $this->get('/dashboard')->assertOk();
        $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $plain])->assertUnauthorized();
    }

    // ================================================================== HIGH: payment concurrency

    public function test_payment_state_changes_exactly_once_under_concurrency(): void
    {
        $payment = $this->pendingPayment(5000);
        $this->fakeVerify(5000);

        $events = 0;
        Event::listen('payment.succeeded', function () use (&$events) {
            $events++;
        });

        // Two "requests" with stale copies of the same pending row.
        $copyA = PaymentRecord::find($payment->id);
        $copyB = PaymentRecord::find($payment->id);
        $service = new PaymentService();

        $this->assertSame(PaymentService::PAID, $service->confirm($copyA));
        $this->assertSame(PaymentService::ALREADY_FINAL, $service->confirm($copyB));
        $this->assertSame(1, $events, 'fulfilment must run once');

        // Even if two requests pass the "is it pending?" check, only one atomic UPDATE can win.
        $method = new \ReflectionMethod(PaymentService::class, 'transition');
        $second = $this->pendingPayment(100);
        $this->assertTrue($method->invoke($service, $second, 'success', []));
        $this->assertFalse($method->invoke($service, $second, 'success', []));
    }

    // ================================================================== HIGH: webhook failures are retried

    public function test_webhook_with_temporary_verification_failure_returns_503_and_is_retried(): void
    {
        $payment = $this->pendingPayment(5000);
        Client::fake(static fn () => new ClientResponse(500, '{"status":false,"message":"gateway down"}'));

        $response = $this->postWebhook($payment->gateway_reference);
        $response->assertStatus(503);
        $event = PaymentWebhookEvent::where('reference', $payment->gateway_reference)->first();
        $this->assertSame('failed', $event->status);
        $this->assertSame('pending', $payment->refresh()->status);

        // Provider is back: reconciliation finishes the job.
        $this->fakeVerify(5000);
        (new PaymentService())->reconcile();
        $this->assertSame('success', $payment->refresh()->status);
        $this->assertSame('processed', $event->refresh()->status);
    }

    public function test_webhook_before_the_local_record_exists_is_kept_and_matched_later(): void
    {
        $reference = 'PAY_LATE_' . bin2hex(random_bytes(4));
        $this->fakeVerify(5000);

        $this->postWebhook($reference)->assertOk();
        $this->assertSame('unmatched', PaymentWebhookEvent::where('reference', $reference)->first()->status);

        $payment = $this->pendingPayment(5000, $reference);
        (new PaymentService())->reconcile();
        $this->assertSame('success', $payment->refresh()->status);
    }

    public function test_duplicate_webhooks_are_ignored(): void
    {
        $payment = $this->pendingPayment(5000);
        $this->fakeVerify(5000);

        $this->postWebhook($payment->gateway_reference)->assertOk();
        $this->postWebhook($payment->gateway_reference)->assertOk();
        $this->assertSame(1, PaymentWebhookEvent::where('reference', $payment->gateway_reference)->count());
    }

    // ================================================================== MEDIUM: reset responses reveal nothing

    public function test_reset_responses_are_identical_for_existing_unknown_and_throttled_addresses(): void
    {
        $this->createUser(['email' => 'known@example.com']);

        $answers = [];
        foreach (['known@example.com', 'known@example.com', 'nobody@example.com', 'nobody@example.com'] as $email) {
            $_SESSION = [];
            $response = $this->post('/forgot-password', ['email' => $email]);
            $answers[] = [$response->status(), \Core\Session::getFlash('success'), \Core\Session::getFlash('errors')];
        }

        foreach ($answers as $answer) {
            $this->assertSame($answers[0], $answer, 'Every case must get exactly the same response');
        }
        $this->assertCount(1, Mail::sentTo('known@example.com'), 'The throttled second request sent nothing');
    }

    // ================================================================== MEDIUM: HTTP client localhost check

    public function test_http_client_only_allows_exact_local_hosts_in_development(): void
    {
        Client::assertSecureUrl('https://api.example.com/x');
        Client::assertSecureUrl('http://localhost:8080/x');
        Client::assertSecureUrl('http://127.0.0.1/x');

        foreach (['http://localhost.example.com/x', 'http://127.0.0.1.evil.io/x', 'http://example.com/x', 'ftp://localhost/x', 'http://user:pw@localhost/x'] as $url) {
            $this->assertThrows(fn () => Client::assertSecureUrl($url), \RuntimeException::class, "$url must be refused");
        }

        Config::set('app.env', 'production');
        $this->assertThrows(fn () => Client::assertSecureUrl('http://localhost/x'), \RuntimeException::class);
    }

    // ================================================================== MEDIUM: forwarded IPs

    public function test_forwarded_ip_is_resolved_from_the_trusted_end(): void
    {
        $ip = static fn (string $remote, string $xff) => (new Request([], [], ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $xff]))->ip();

        Config::set('security.trusted_proxies', []);
        $this->assertSame('203.0.113.5', $ip('203.0.113.5', '1.1.1.1'), 'XFF ignored without trusted proxies');

        Config::set('security.trusted_proxies', ['10.0.0.0/8']);
        // Attacker sends a fake "1.1.1.1"; our proxy APPENDS their real IP 7.7.7.7.
        $this->assertSame('7.7.7.7', $ip('10.0.0.2', '1.1.1.1, 7.7.7.7'));
        // Two of our proxies in the chain are skipped.
        $this->assertSame('8.8.4.4', $ip('10.0.0.2', '8.8.4.4, 10.1.2.3'));
        // Malformed entries never win.
        $this->assertSame('10.0.0.2', $ip('10.0.0.2', 'not-an-ip'));
        // IPv6 CIDR.
        Config::set('security.trusted_proxies', ['2400:cb00::/32']);
        $this->assertSame('2001:db8::1', $ip('2400:cb00::5', '2001:db8::1'));
    }

    // ================================================================== MEDIUM: Host header

    public function test_untrusted_host_headers_are_rejected_and_production_requires_app_url(): void
    {
        $this->withHeaders(['Host' => 'evil.example'])->get('/')->assertStatus(400);
        $this->withHeaders(['Host' => 'localhost'])->get('/')->assertOk();

        Config::set('app.url', '');
        Config::set('app.env', 'production');
        $this->assertThrows(fn () => URL::to('/reset'), \RuntimeException::class);
    }

    // ================================================================== sessions & API

    public function test_api_requests_are_stateless(): void
    {
        $this->app->router->get('/api/_probe', fn () => ['stateless' => Session::isStateless(), 'user' => Auth::id(), 'session' => $_SESSION]);
        $this->actingAs($this->createUser());

        $json = $this->getJson('/api/_probe')->json();
        $this->assertTrue($json['stateless']);
        $this->assertNull($json['user'], 'A web session must not authenticate API requests');
        $this->assertSame([], $json['session']);

        // The web session is untouched afterwards.
        $this->get('/dashboard')->assertOk();
    }

    public function test_sessions_have_an_absolute_lifetime(): void
    {
        $this->actingAs($this->createUser());
        $this->get('/dashboard')->assertOk();

        $_SESSION['_started_at'] = time() - (int) config('session.absolute_lifetime') * 60 - 5;
        $_SESSION['_last_activity'] = time();   // active, but too old
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_changing_email_requires_the_current_password(): void
    {
        $this->actingAs($this->createUser(['email' => 'me@example.com', 'password' => 'Pass#1234']));

        $this->call('POST', '/profile', ['_method' => 'PATCH', 'name' => 'Me', 'email' => 'new@example.com'])->assertSessionHasErrors(['email_current_password']);
        $this->call('POST', '/profile', ['_method' => 'PATCH', 'name' => 'Me', 'email' => 'new@example.com', 'email_current_password' => 'wrong'])->assertSessionHasErrors(['email_current_password']);
        $this->get('/profile');   // shows (and consumes) the previous errors, like a browser would
        $this->call('POST', '/profile', ['_method' => 'PATCH', 'name' => 'Me', 'email' => 'new@example.com', 'email_current_password' => 'Pass#1234'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    // ================================================================== two-factor authentication

    public function test_two_factor_login_flow_with_replay_protection_and_recovery_codes(): void
    {
        $user = $this->createUser(['email' => '2fa@example.com', 'password' => 'Pass#1234']);
        $codes = $user->enableTwoFactor();
        $user->forceFill(['two_factor_confirmed_at' => date('Y-m-d H:i:s')])->save();
        $secret = (string) $user->refresh()->two_factor_secret;

        $this->post('/login', ['email' => '2fa@example.com', 'password' => 'Pass#1234'])->assertRedirect('/two-factor-challenge');
        $this->get('/dashboard')->assertRedirect('/login'); // not signed in yet

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors(['code']);
        $code = \Core\Auth\TwoFactor::currentCode($secret);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        // The same code can't be used twice.
        $this->assertFalse(\Core\Auth\TwoFactor::verify($secret, $code, 'user:' . $user->id));

        // Recovery codes work once.
        $this->post('/logout');
        $this->post('/login', ['email' => '2fa@example.com', 'password' => 'Pass#1234']);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertRedirect('/dashboard');
        $this->assertFalse($user->refresh()->verifyTwoFactor($codes[0]));
    }

    public function test_totp_matches_the_rfc_6238_reference_values(): void
    {
        // RFC 6238 test secret "12345678901234567890" (SHA1), 8-digit vectors truncated to 6 digits.
        $key = '12345678901234567890';
        $this->assertSame('287082', \Core\Auth\TwoFactor::codeAt($key, intdiv(59, 30)));
        $this->assertSame('081804', \Core\Auth\TwoFactor::codeAt($key, intdiv(1111111109, 30)));
        $this->assertSame($key, \Core\Auth\TwoFactor::base32Decode(\Core\Auth\TwoFactor::base32Encode($key)));
    }

    // ================================================================== rate limiter / cache / queue

    public function test_rate_limiter_fails_closed_prunes_and_supports_a_shared_database_store(): void
    {
        Config::set('security.rate_limiter.store', 'database');
        RateLimiter::hit('db-key', 60);
        $this->assertSame(2, RateLimiter::hit('db-key', 60));
        RateLimiter::hit('old-key', -10);
        $this->assertSame(1, RateLimiter::prune());

        if (!$this->usesRealDatabase()) {
            \Core\Database\Schema::drop('rate_limits');   // simulate a broken store
            $this->assertTrue(RateLimiter::tooManyAttempts('x', 5), 'fails closed by default');
            $this->assertFalse(RateLimiter::tooManyAttempts('x', 5, failOpen: true));
        }
        Config::set('security.rate_limiter.store', 'file');
    }

    public function test_cache_rejects_tampering_and_never_builds_objects(): void
    {
        Cache::put('k', ['a' => 1], 60);
        $this->assertSame(['a' => 1], Cache::get('k'));

        $file = storage_path('framework/cache/' . hash('sha256', 'k') . '.cache');
        file_put_contents($file, str_replace('v1:', 'v1:0', (string) file_get_contents($file)));
        $this->assertNull(Cache::get('k'), 'tampered entry ignored');

        Cache::put('obj', new \ArrayObject([1]), 60);
        $this->assertFalse(Cache::get('obj') instanceof \ArrayObject, 'objects are not restored');
    }

    public function test_queue_payloads_are_json_and_restricted(): void
    {
        Config::set('queue.default', 'database');
        dispatch(new \App\Jobs\SendWelcomeEmail(42));
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertSame(['job' => 'App\\Jobs\\SendWelcomeEmail', 'data' => json_decode($payload, true)['data']], json_decode($payload, true));
        $this->assertFalse(str_contains($payload, 'O:'), 'no PHP serialization');

        // A tampered payload naming another class is refused and moved to failed_jobs.
        DB::table('jobs')->where('queue', 'default')->update(['payload' => json_encode(['job' => 'Core\\Database', 'data' => []])]);
        \Core\Queue\Queue::work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);

        // Jobs may not carry objects.
        $this->assertThrows(fn () => \Core\Queue\Payload::encode(new class (new \stdClass()) extends \Core\Queue\Job {
            public function __construct(public object $thing) {}
            public function handle(): void {}
        }), \InvalidArgumentException::class);
    }

    public function test_key_rotation_keeps_old_data_working(): void
    {
        $oldKey = (string) Config::get('app.key');
        $encrypted = Crypt::encrypt('secret');
        $hash = Token::hash('api-token');
        $signed = Token::sign(['x' => 1], 10, 'p');

        Config::set('app.key', Crypt::generateKey());
        Config::set('app.previous_keys', [$oldKey]);

        $this->assertSame('secret', Crypt::decrypt($encrypted));
        $this->assertTrue(Token::matches($hash, 'api-token'));
        $this->assertSame(['x' => 1], Token::verify($signed, 'p'));

        Config::set('app.previous_keys', []);
        $this->assertNull(Crypt::tryDecrypt($encrypted), 'without the old key, old data is unreadable');
        Config::set('app.key', $oldKey);
    }

    // ================================================================== authorization policies

    public function test_policies_enforce_ownership(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $payment = $this->pendingPayment(100, null, (int) $owner->id);

        $this->assertTrue($owner->can('view', $payment));
        $this->assertFalse($other->can('view', $payment));
        $this->assertFalse($other->can('delete', $payment), 'missing policy methods deny');
        $this->assertFalse(\Core\Auth\Gate::forUser(null, 'view', $payment), 'guests are denied');
        $this->assertFalse(\Core\Auth\Gate::allows('no-such-ability'), 'unknown abilities deny');

        $admin = $this->createUser(['role' => 'admin']);
        $this->assertTrue($admin->can('view', $payment));
    }

    // ================================================================== operations

    public function test_sqlite_backups_are_created_and_compressed(): void
    {
        if ($this->usesRealDatabase()) {
            return;
        }

        $command = $this->app->make(\Core\Console\Commands\DbBackupCommand::class);
        $command->setInput(['--keep=1']);
        ob_start();
        $command->handle();
        ob_end_clean();

        $files = glob(storage_path('backups') . '/testing-*.sqlite.gz') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringContainsString('SQLite format 3', (string) gzdecode((string) file_get_contents($files[0])));
        array_map('unlink', $files);
    }

    public function test_all_three_email_templates_render_safely(): void
    {
        foreach (['modern', 'classic', 'minimal'] as $theme) {
            $layout = resource_path("views/emails/_layout_$theme.blade.php");
            $child = resource_path("views/emails/_child_$theme.blade.php");
            copy(base_path("core/stubs/mail-themes/$theme.blade.php"), $layout);
            file_put_contents($child, "@extends('emails._layout_$theme')\n@section('content')<p>{{ \$name }}</p>@endsection");

            try {
                $html = $this->app->view->render("emails._child_$theme", ['name' => '<script>x</script>', 'subject' => 'Hi']);
                $this->assertStringContainsString('<!doctype html>', strtolower($html));
                $this->assertStringContainsString('&lt;script&gt;', $html, "$theme escapes content");
                $this->assertFalse(str_contains($html, '<script>x'), "$theme must not output raw script");
                $this->assertStringContainsString('role="presentation"', $html, "$theme uses email-safe tables");
            } finally {
                @unlink($layout);
                @unlink($child);
            }
        }
    }

    // ================================================================== helpers

    private function pendingPayment(int $amountMinor, ?string $reference = null, ?int $userId = null): PaymentRecord
    {
        $reference ??= 'PAY_' . bin2hex(random_bytes(6));
        $payment = new PaymentRecord(['description' => 'Test']);
        $payment->forceFill([
            'user_id' => $userId ?? (int) $this->createUser()->id,
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
