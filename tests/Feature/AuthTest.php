<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Core\Mail\Mail;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function test_welcome_page_shows_the_framework_name(): void
    {
        $this->get('/')->assertOk()->assertSee('INB PHP Framework')->assertSee('Log in');
    }

    public function test_guests_are_redirected_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_validates_input(): void
    {
        $this->post('/register', ['name' => '<b>x</b>', 'email' => 'bad', 'password' => 'weak', 'password_confirmation' => 'nope'])
            ->assertRedirect()
            ->assertSessionHasErrors(['name', 'email', 'password', 'terms']);
    }

    public function test_a_user_can_register_and_must_verify_their_email(): void
    {
        $this->post('/register', [
            'name' => 'Ama Mensah',
            'email' => 'Ama@Example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'terms' => '1',
            'role' => 'admin',                      // mass-assignment attempt: must be ignored
        ])->assertRedirect('/verify-email');

        $user = User::where('email', 'ama@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('user', $user->role);
        $this->assertTrue(password_verify('Secret#123', $user->getAttributes()['password']));
        $this->assertCount(1, Mail::sentTo('ama@example.com'));

        // Unverified users can't reach verified pages.
        $this->get('/dashboard')->assertRedirect('/verify-email');

        // The signed link from the email verifies the account.
        $this->get(parse_url($user->verificationUrl(), PHP_URL_PATH) . '?' . parse_url($user->verificationUrl(), PHP_URL_QUERY))
            ->assertRedirect('/dashboard');
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->get('/dashboard')->assertOk()->assertSee('Ama Mensah');
    }

    public function test_tampered_verification_links_are_rejected(): void
    {
        $user = $this->createUser(['email_verified_at' => null]);
        $this->actingAs($user);

        $url = $user->verificationUrl();
        $tampered = str_replace('/verify-email/' . $user->id . '/', '/verify-email/999/', (string) parse_url($url, PHP_URL_PATH)) . '?' . parse_url($url, PHP_URL_QUERY);

        $this->get($tampered)->assertForbidden();
    }

    public function test_users_can_log_in_and_out(): void
    {
        $user = $this->createUser(['email' => 'kofi@example.com', 'password' => 'Secret#123']);

        $this->post('/login', ['email' => 'KOFI@example.com', 'password' => 'Secret#123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        $this->post('/logout')->assertRedirect('/');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_wrong_password_shows_a_generic_error(): void
    {
        $this->createUser(['email' => 'kofi@example.com', 'password' => 'Secret#123']);

        $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'wrong'])->assertRedirect()->assertSessionHasErrors(['email']);
        $this->get('/login')->assertSee('These credentials do not match our records.');
    }

    public function test_accounts_lock_after_too_many_failed_logins(): void
    {
        $this->createUser(['email' => 'kofi@example.com', 'password' => 'Secret#123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'wrong-' . $i]);
        }

        // Even the correct password is refused while locked.
        $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'Secret#123'])->assertSessionHasErrors(['email']);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_after_login_users_return_to_the_page_they_wanted(): void
    {
        $this->createUser(['email' => 'kofi@example.com', 'password' => 'Secret#123']);

        $this->get('/profile')->assertRedirect('/login');
        $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'Secret#123'])->assertRedirect('/profile');
    }

    public function test_password_reset_flow(): void
    {
        $user = $this->createUser(['email' => 'esi@example.com', 'password' => 'Old#Pass123']);

        $this->post('/forgot-password', ['email' => 'esi@example.com'])->assertRedirect();
        $sent = Mail::sentTo('esi@example.com');
        $this->assertCount(1, $sent);

        preg_match('#reset-password/([A-Za-z0-9]+)#', $sent[0]['raw'] ? quoted_printable_decode($sent[0]['raw']) : '', $m);
        $this->assertNotNull($m[1] ?? null, 'Reset link not found in the email.');

        $this->post('/reset-password', ['token' => 'wrong', 'email' => 'esi@example.com', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])
            ->assertSessionHasErrors(['email']);

        $this->post('/reset-password', ['token' => $m[1], 'email' => 'esi@example.com', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])
            ->assertRedirect('/login');

        $this->assertTrue(password_verify('New#Pass123', $user->refresh()->getAttributes()['password']));

        // Tokens are single-use.
        $this->post('/reset-password', ['token' => $m[1], 'email' => 'esi@example.com', 'password' => 'Other#Pass123', 'password_confirmation' => 'Other#Pass123'])
            ->assertSessionHasErrors(['email']);
    }

    public function test_unknown_emails_get_the_same_reset_answer(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertRedirect();
        $this->assertCount(0, Mail::$sent);
        $this->get('/forgot-password')->assertSee('If an account exists for that email');
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = $this->createUser(['password' => 'Old#Pass123']);
        $this->actingAs($user);

        $this->put('/profile/password', ['current_password' => 'nope', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])
            ->assertSessionHasErrors(['current_password']);

        $this->put('/profile/password', ['current_password' => 'Old#Pass123', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])
            ->assertRedirect();
        $this->assertTrue(password_verify('New#Pass123', $user->refresh()->getAttributes()['password']));
    }
}
