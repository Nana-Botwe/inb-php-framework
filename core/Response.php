<?php

declare(strict_types=1);

namespace Core;

use JsonSerializable;

/**
 * Everything a controller returns becomes a Response. Nothing is echoed until send(),
 * so redirects never fail with "headers already sent".
 */
class Response
{
    /** @var array<string, array{value: string, expires: int, httponly: bool}> */
    protected array $cookies = [];

    /** @var array<string, array{value: string, expires: int, httponly: bool}> Cookies queued from anywhere (e.g. Auth). */
    private static array $queuedCookies = [];

    /** @param array<string, string> $headers */
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = [],
    ) {
    }

    public static function noContent(int $status = 204): static
    {
        return new static('', $status);
    }

    /** Queue a cookie to be attached to whatever response is sent. */
    public static function queueCookie(string $name, string $value, int $minutes = 0, bool $httpOnly = true): void
    {
        self::$queuedCookies[$name] = ['value' => $value, 'expires' => $minutes === 0 ? 0 : time() + $minutes * 60, 'httponly' => $httpOnly];
    }

    /** @internal tests: send() normally clears the queue. */
    public static function flushQueuedCookies(): void
    {
        self::$queuedCookies = [];
    }

    public static function forgetCookie(string $name): void
    {
        self::$queuedCookies[$name] = ['value' => '', 'expires' => time() - 3600, 'httponly' => true];
    }

    /** @return array<string, array{value: string, expires: int, httponly: bool}> */
    public function getCookies(): array
    {
        return $this->cookies + self::$queuedCookies;
    }

    /** Attach a cookie (HttpOnly + SameSite=Lax by default, Secure on HTTPS). */
    public function cookie(string $name, string $value, int $minutes = 0, bool $httpOnly = true): static
    {
        $this->cookies[$name] = ['value' => $value, 'expires' => $minutes === 0 ? 0 : time() + $minutes * 60, 'httponly' => $httpOnly];

        return $this;
    }

    public function withoutCookie(string $name): static
    {
        $this->cookies[$name] = ['value' => '', 'expires' => time() - 3600, 'httponly' => true];

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public static function html(string $content, int $status = 200): static
    {
        return new static($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): static
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new static($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Redirect to an app path ("/login") or a full URL ("https://..."). */
    public static function redirect(string $to, int $status = 302): static
    {
        // Accept app paths ("/login") as well as URLs that already contain the base folder (from route()/url()).
        $base = rtrim(url('/'), '/');
        $alreadyPrefixed = $base !== '' && ($to === $base || str_starts_with($to, $base . '/') || str_starts_with($to, $base . '?'));
        $location = preg_match('#^(https?:)?//#i', $to) || $alreadyPrefixed ? $to : url($to);

        return new static('', $status, ['Location' => $location]);
    }

    /**
     * Redirect to the page the user originally wanted (saved by the auth middleware), or $default.
     * Only internal paths are accepted, so this can't be abused as an open redirect.
     */
    public static function intended(string $default = '/'): static
    {
        $target = Session::pull('_intended');

        return static::redirect(self::isSafePath($target) ? (string) $target : $default);
    }

    /** True for app-relative paths like "/orders?page=2" (not "//evil.com" or "https://..."). */
    public static function isSafePath(mixed $path): bool
    {
        return is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')
            && !str_contains($path, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $path);
    }

    /** Normalise whatever a controller returned into a Response. */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_array($value), $value instanceof JsonSerializable => self::json($value),
            default => self::html((string) $value),
        };
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** Flash a value to the session for the next request (e.g. ->with('success', 'Saved!')). */
    public function with(string $key, mixed $value): static
    {
        Session::flash($key, $value);

        return $this;
    }

    /** @param array<string, string[]|string> $errors */
    public function withErrors(array $errors): static
    {
        return $this->with('errors', array_map(static fn ($e) => (array) $e, $errors));
    }

    /** @param array<string, mixed> $input */
    public function withInput(array $input): static
    {
        return $this->with('_old_input', $input);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By'); // don't advertise the PHP version

            foreach ($this->securityHeaders() + $this->headers as $name => $value) {
                // Strip CR/LF so a value can never inject extra headers.
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }

            $secure = App::instance()->request()?->isSecure() ?? false;
            foreach ($this->getCookies() as $name => $cookie) {
                setcookie($name, $cookie['value'], [
                    'expires' => $cookie['expires'],
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => $cookie['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            self::$queuedCookies = [];
        }

        echo $this->content;
    }

    /** @return array<string, string> */
    public function securityHeaders(): array
    {
        $headers = (array) Config::get('security.headers', []);

        $csp = Csp::header();
        if ($csp !== null) {
            $headers['Content-Security-Policy'] = $csp;
        }

        $hsts = Config::get('security.hsts');
        if (is_string($hsts) && $hsts !== '' && App::instance()->request()?->isSecure()) {
            $headers['Strict-Transport-Security'] = $hsts;
        }

        // Pages that can contain personal data must not be cached by shared proxies.
        if (Session::has('_auth_id')) {
            $headers['Cache-Control'] = 'no-store, private';
        }

        return array_map('strval', $headers);
    }
}
