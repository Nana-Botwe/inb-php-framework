<?php

declare(strict_types=1);

namespace Core\Testing;

use Core\Response;
use Core\Session;
use Core\Support\Arr;

/**
 * Fluent assertions on a response:  $this->get('/')->assertOk()->assertSee('Welcome');
 */
final class TestResponse
{
    public function __construct(public readonly Response $response, private readonly TestCase $test)
    {
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function content(): string
    {
        return $this->response->content();
    }

    public function json(?string $key = null): mixed
    {
        $data = json_decode($this->content(), true);

        return $key === null ? $data : Arr::get($data, $key);
    }

    public function assertStatus(int $status): self
    {
        $this->test->assert($this->status() === $status, "Expected status $status, got {$this->status()}." . $this->hint());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    /** Redirect, optionally to a path ending with $to (e.g. "/login"). */
    public function assertRedirect(?string $to = null): self
    {
        $location = (string) $this->response->getHeader('Location');
        $this->test->assert(in_array($this->status(), [301, 302, 303, 307, 308], true), "Expected a redirect, got status {$this->status()}." . $this->hint());

        if ($to !== null) {
            $path = (string) parse_url($location, PHP_URL_PATH);
            $this->test->assert(rtrim($path, '/') === rtrim($to, '/') || str_ends_with(rtrim($path, '/'), rtrim($to, '/')), "Expected redirect to [$to], got [$location].");
        }

        return $this;
    }

    public function assertSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? e($text) : $text;
        $this->test->assert(str_contains($this->content(), $needle), "Expected to see \"$text\" in the response.");

        return $this;
    }

    public function assertDontSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? e($text) : $text;
        $this->test->assert(!str_contains($this->content(), $needle), "Did not expect to see \"$text\" in the response.");

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->response->getHeader($name) ?? $this->response->securityHeaders()[$name] ?? null;
        $this->test->assert($actual !== null, "Header [$name] is missing.");
        if ($value !== null) {
            $this->test->assert($actual === $value, "Header [$name] is [$actual], expected [$value].");
        }

        return $this;
    }

    /** @param array<string, mixed> $subset */
    public function assertJson(array $subset): self
    {
        $data = (array) $this->json();
        foreach ($subset as $key => $value) {
            $this->test->assert(Arr::get($data, (string) $key) == $value, "JSON [$key] does not match.");
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->json($path);
        $this->test->assert($actual === $expected, "JSON [$path] is " . json_encode($actual) . ', expected ' . json_encode($expected) . '.');

        return $this;
    }

    /** @param string[] $fields */
    public function assertSessionHasErrors(array $fields = []): self
    {
        $errors = (array) Session::getFlash('errors', []);
        $this->test->assert($errors !== [], 'Expected validation errors in the session, found none.');
        foreach ($fields as $field) {
            $this->test->assert(isset($errors[$field]), "Expected a validation error for [$field]. Errors: " . implode(', ', array_keys($errors)));
        }

        return $this;
    }

    public function assertSessionHasNoErrors(): self
    {
        $errors = (array) Session::getFlash('errors', []);
        $this->test->assert($errors === [], 'Unexpected validation errors: ' . json_encode($errors));

        return $this;
    }

    private function hint(): string
    {
        $location = $this->response->getHeader('Location');
        if ($location !== null) {
            return " (redirect to $location)";
        }
        if (preg_match('#<h1[^>]*>(.*?)</h1>#s', $this->content(), $m)) {
            return ' Page says: ' . trim(strip_tags($m[1]));
        }

        return '';
    }
}
