<?php

declare(strict_types=1);

namespace Core\Http;

use RuntimeException;

/**
 * Small HTTP client (cURL) used by the payment and SMS gateways.
 * TLS certificates are always verified and every request has a timeout.
 *
 *   $res = Client::make()->withToken($key)->asJson()->post('https://api.example.com/x', ['a' => 1]);
 *   $res->ok(); $res->json('data.id'); $res->status();
 *
 * Tests: Client::fake(fn (array $request) => new ClientResponse(200, '{"ok":true}'));
 */
final class Client
{
    /** @var (callable(array): ClientResponse)|null */
    private static $fake = null;

    /** @var array<int, array<string, mixed>> Requests recorded while faking. */
    public static array $recorded = [];

    /** @var array<string, string> */
    private array $headers = ['Accept' => 'application/json', 'User-Agent' => 'INB-PHP-Framework/1.0'];

    private string $bodyFormat = 'json';

    private int $timeout = 20;

    private ?string $basicAuth = null;

    public static function make(): self
    {
        return new self();
    }

    /** @param callable(array): ClientResponse $handler */
    public static function fake(callable $handler): void
    {
        self::$fake = $handler;
        self::$recorded = [];
    }

    public static function stopFaking(): void
    {
        self::$fake = null;
    }

    /**
     * Only HTTPS is allowed. Plain HTTP is accepted solely for the exact hosts localhost, 127.0.0.1
     * and ::1, and only in local/testing environments (or with http.allow_insecure_local = true).
     * "http://localhost.example.com" or "http://127.0.0.1.evil.io" are NOT treated as local.
     */
    public static function assertSecureUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Refusing to call an invalid URL.');
        }

        if ($scheme === 'https') {
            return;
        }

        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $localAllowed = in_array(\Core\Config::get('app.env'), ['local', 'testing'], true)
            || (bool) \Core\Config::get('http.allow_insecure_local', false);

        if ($scheme === 'http' && $localHost && $localAllowed) {
            return;
        }

        throw new RuntimeException('Refusing to send data over an insecure (non-HTTPS) connection: ' . $scheme . '://' . $host);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = str_replace(["\r", "\n"], '', $value);
        }

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => $type . ' ' . $token]);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        $this->basicAuth = $username . ':' . $password;

        return $this;
    }

    public function asJson(): self
    {
        $this->bodyFormat = 'json';

        return $this;
    }

    public function asForm(): self
    {
        $this->bodyFormat = 'form';

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', $query === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query));
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = []): ClientResponse
    {
        return $this->send('POST', $url, $data);
    }

    /** @param array<string, mixed> $data */
    public function put(string $url, array $data = []): ClientResponse
    {
        return $this->send('PUT', $url, $data);
    }

    /** @param array<string, mixed> $data */
    public function delete(string $url, array $data = []): ClientResponse
    {
        return $this->send('DELETE', $url, $data);
    }

    /** @param array<string, mixed>|null $data */
    public function send(string $method, string $url, ?array $data = null): ClientResponse
    {
        self::assertSecureUrl($url);

        $headers = $this->headers;
        $body = null;
        if ($data !== null && $method !== 'GET') {
            if ($this->bodyFormat === 'json') {
                $headers['Content-Type'] = 'application/json';
                $body = (string) json_encode($data === [] ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES);
            } else {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $body = http_build_query($data);
            }
        }
        if ($this->basicAuth !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->basicAuth);
        }

        if (self::$fake !== null) {
            $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            self::$recorded[] = $request;

            return (self::$fake)($request);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        return new ClientResponse($status, (string) $response);
    }
}
