# Testing

The framework includes its own test runner — no PHPUnit needed.

```bash
php console test                 # everything in tests/
php console test AuthTest        # filter by class or method name
php console make:test OrderTest
```

Each test runs against a **fresh in-memory SQLite database** with all migrations applied. Emails go to an in-memory mailbox, SMS to the log driver, queued jobs run immediately, and bcrypt uses a low cost so tests are fast.

## Writing a test

```php
namespace Tests\Feature;

use Tests\TestCase;

final class OrderTest extends TestCase
{
    public function test_guests_cannot_see_orders(): void
    {
        $this->get('/orders')->assertRedirect('/login');
    }

    public function test_customers_can_place_an_order(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/orders', ['product_id' => 1, 'qty' => 2])
            ->assertRedirect('/orders');

        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'qty' => 2]);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->createUser())
            ->post('/orders', ['qty' => 0])
            ->assertSessionHasErrors(['product_id', 'qty']);
    }
}
```

Requests go through the whole application — firewall, middleware, CSRF (a valid token is added for you), routing and views.

## Requests

```php
$this->get('/path');
$this->post('/path', $data, $headers, $files);
$this->put('/path', $data);   $this->delete('/path');
$this->getJson('/api/user', ['Authorization' => 'Bearer ' . $token]);
$this->postJson('/api/tokens', $data);
$this->withHeaders(['User-Agent' => 'Test']);
$this->actingAs($user);
$this->post('/avatar', [], [], ['avatar' => $this->fakeImage('me.jpg', 1200, 800)]);
$this->post('/docs', [], [], ['file' => $this->fakeUpload('a.csv', "id,name\n1,Ama")]);
```

## Response assertions

`assertOk()`, `assertStatus(201)`, `assertCreated()`, `assertNotFound()`, `assertForbidden()`, `assertUnauthorized()`, `assertRedirect('/login')`, `assertSee('Welcome')`, `assertDontSee('secret')`, `assertHeader('X-Frame-Options', 'SAMEORIGIN')`, `assertJson(['status' => 'ok'])`, `assertJsonPath('data.email', 'a@b.com')`, `assertSessionHasErrors(['email'])`, `assertSessionHasNoErrors()`. Use `->content()` and `->json('key')` to inspect.

## Other assertions

`assertTrue`, `assertFalse`, `assertSame`, `assertEquals`, `assertNotSame`, `assertNull`, `assertNotNull`, `assertCount`, `assertContains`, `assertStringContainsString`, `assertInstanceOf`, `assertThrows(fn () => ..., Exception::class)`, `assertDatabaseHas`, `assertDatabaseMissing`, `assertDatabaseCount`.

## Faking external services

```php
use Core\Http\Client;
use Core\Http\ClientResponse;
use Core\Mail\Mail;

Client::fake(fn (array $request) => new ClientResponse(200, '{"status":"success"}'));
// ... code that calls a payment or SMS API ...
Client::$recorded;              // the requests that would have been sent

Mail::sentTo('ama@example.com');   // emails sent during the test
```

## What the framework's own tests cover

The included tests (in `tests/`) check registration, login, lockout, password reset, email verification, CSRF, the firewall and bans, XSS escaping, SQL-injection guards, roles, session hijack protection, encryption, signed tokens, API tokens, payments (including underpayment detection and webhook signatures), SMS, upload compression and the rejection of disguised PHP files. Keep them passing when you change the framework.

## Testing on your real database engine

By default every test uses an in-memory SQLite database. To run the same tests on MySQL, MariaDB, PostgreSQL or SQL Server, point `TEST_DB_URL` at an empty database whose **name contains "test"** (a safety check so real data can never be wiped):

```bash
TEST_DB_URL=mysql://user:pass@127.0.0.1:3306/my_shop_test php console test
TEST_DB_URL=pgsql://user:pass@127.0.0.1:5432/my_shop_test php console test
```

The database is wiped and migrated once per run, and every test runs inside a transaction that is rolled back afterwards.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

- `php console lint` and the full test suite on PHP 8.2, 8.3 and 8.4
- each against SQLite, MySQL 8.4 and PostgreSQL 16
- PHPStan (`phpstan.neon`, level 6) and PHP-CS-Fixer (`.php-cs-fixer.dist.php`, PSR-12)
- `php console security:check`

Locally: `composer install`, then `composer analyse` and `composer format`.
