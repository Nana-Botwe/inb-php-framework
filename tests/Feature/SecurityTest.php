<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Core\Crypt;
use Core\Csrf;
use Core\DB;
use Core\Token;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    public function test_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/login', ['email' => 'a@b.com', 'password' => 'x', '_token' => 'forged'])->assertStatus(419);
    }

    public function test_csrf_tokens_are_masked_differently_every_time(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertNotSame($a, $b);
        $this->assertTrue(Csrf::verify($a));
        $this->assertTrue(Csrf::verify($b));
        $this->assertFalse(Csrf::verify(substr($a, 0, -2) . 'xx'));
    }

    public function test_cross_site_origin_is_rejected(): void
    {
        $this->post('/login', ['email' => 'a@b.com', 'password' => 'x'], ['Origin' => 'https://evil.example'])->assertStatus(419);
    }

    public function test_firewall_blocks_attacks(): void
    {
        $this->get('/?q=1%27%20UNION%20SELECT%20password%20FROM%20users--')->assertForbidden();
        $this->get('/?name=%3Cscript%3Ealert(1)%3C/script%3E')->assertForbidden();
        $this->get('/../../etc/passwd')->assertForbidden();
        $this->get('/?file=php://filter/resource=index.php')->assertForbidden();
        $this->get('/.env')->assertNotFound();
        $this->get('/backup/app/core/config.php')->assertNotFound();
        $this->get('/wp-login.php')->assertNotFound();
        $this->withHeaders(['User-Agent' => 'sqlmap/1.7'])->get('/')->assertForbidden();
    }

    public function test_repeat_attackers_are_banned(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/?x=%3Cscript%3E' . $i);
        }
        // Now even a normal request from that IP is refused.
        $this->get('/')->assertForbidden();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_output_is_escaped(): void
    {
        $user = $this->createUser(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user);

        $html = $this->get('/dashboard')->assertOk()->content();
        $this->assertFalse(str_contains($html, '<script>alert(1)</script>'));
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_sql_identifiers_cannot_be_injected(): void
    {
        $this->assertThrows(fn () => User::where('email = 1 OR 1=1 --', 'x')->get(), \InvalidArgumentException::class);
        $this->assertThrows(fn () => User::query()->orderBy('id; DROP TABLE users')->get(), \InvalidArgumentException::class);
        $this->assertThrows(fn () => DB::table('users')->delete(), \LogicException::class);
    }

    public function test_values_are_bound_not_concatenated(): void
    {
        $this->createUser(['email' => 'safe@example.com']);
        $this->assertNull(User::where('email', "' OR '1'='1")->first());
        $this->assertCount(1, User::all());
    }

    public function test_users_cannot_reach_admin_pages(): void
    {
        $this->actingAs($this->createUser());
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_admins_can_reach_admin_pages_with_two_factor_and_confirmed_password(): void
    {
        $admin = $this->createUser(['role' => 'admin', 'password' => 'Admin#Pass1']);
        $admin->enableTwoFactor();
        $admin->forceFill(['two_factor_confirmed_at' => date('Y-m-d H:i:s')])->save();
        $this->actingAs($admin);

        // Password must be confirmed first ("sudo mode").
        $this->get('/admin/users')->assertRedirect('/confirm-password');
        $this->post('/confirm-password', ['password' => 'Admin#Pass1'])->assertRedirect('/admin/users');
        $this->get('/admin/users')->assertOk()->assertSee($admin->email);
    }

    public function test_admins_without_two_factor_are_sent_to_set_it_up(): void
    {
        $this->actingAs($this->createUser(['role' => 'admin']));
        $this->get('/admin/users')->assertRedirect('/profile');
    }

    public function test_session_is_dropped_when_the_browser_changes(): void
    {
        $this->actingAs($this->createUser());
        $this->get('/dashboard')->assertOk();

        // Same session cookie, different browser = probably a stolen cookie.
        $this->withHeaders(['User-Agent' => 'Some Other Browser'])->get('/dashboard')->assertRedirect('/login');
    }

    public function test_encryption_detects_tampering(): void
    {
        $token = Crypt::encrypt(['id' => 5]);
        $this->assertSame(['id' => 5], Crypt::decrypt($token));
        $this->assertNull(Crypt::tryDecrypt(substr($token, 0, -3) . 'AAA'));
    }

    public function test_signed_tokens_expire_and_cannot_be_forged(): void
    {
        $token = Token::sign(['user' => 5], 10, 'invite');
        $this->assertSame(['user' => 5], Token::verify($token, 'invite'));
        $this->assertNull(Token::verify($token, 'other-purpose'));
        $this->assertNull(Token::verify(str_replace('.', '.x', $token), 'invite'));
        $this->assertNull(Token::verify(Token::sign(['user' => 5], -1, 'invite'), 'invite'));
    }

    public function test_api_requires_a_valid_token(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();

        $user = $this->createUser(['email' => 'api@example.com', 'password' => 'Secret#123']);
        $response = $this->postJson('/api/tokens', ['email' => 'api@example.com', 'password' => 'Secret#123', 'device_name' => 'phone'])->assertCreated();
        $token = (string) $response->json('token');

        // Only a hash is stored.
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => explode('|', $token)[1]]);

        $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $token])->assertOk()->assertJsonPath('data.email', 'api@example.com');
        $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $token . 'x'])->assertUnauthorized();

        $data = (array) $this->getJson('/api/user', ['Authorization' => 'Bearer ' . $token])->json('data');
        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'security_stamp'] as $secret) {
            $this->assertFalse(array_key_exists($secret, $data), "API must not expose $secret");
        }
        $this->assertSame(1, count($user->tokens()));
    }

    public function test_production_errors_hide_details_and_show_a_reference(): void
    {
        $this->app->router->get('/boom', fn () => throw new \RuntimeException('secret database detail'));

        $response = $this->get('/boom')->assertStatus(500);
        $this->assertFalse(str_contains($response->content(), 'secret database detail'));
        $this->assertStringContainsString('Error reference: ERR-', $response->content());
    }

    public function test_debug_errors_show_a_solution(): void
    {
        \Core\Config::set('app.debug', true);
        \Core\ErrorHandler::register(true, storage_path('logs'));
        $this->app->router->get('/missing-view', fn () => view('does.not.exist'));

        $html = $this->get('/missing-view')->assertStatus(500)->content();
        $this->assertStringContainsString('View file not found', $html);
        $this->assertStringContainsString('resources/views/does/not/exist.blade.php', $html);
        \Core\ErrorHandler::register(false, storage_path('logs'));
    }
}
