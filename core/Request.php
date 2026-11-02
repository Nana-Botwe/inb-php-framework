<?php

declare(strict_types=1);

namespace Core;

/**
 * Wraps the PHP superglobals so controllers never touch $_GET / $_POST / $_SERVER directly.
 */
final class Request
{
    private ?string $basePath = null;

    /** @var array<string, string> */
    private array $params = [];

    /** Fields that are never trimmed (spaces in a password are meaningful). */
    private const NO_TRIM = ['password', 'password_confirmation', 'current_password', 'email_current_password', 'password_delete'];

    /** @var array<string, mixed> */
    private readonly array $query;

    /** @var array<string, mixed> */
    private readonly array $body;

    /**
     * Input is filtered on the way in: null bytes and invalid UTF-8 are removed and text is trimmed.
     * (HTML is NOT stripped here — escape output with e() and validate with rules like no_html.)
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        array $query,
        array $body,
        private readonly array $server,
        private readonly array $files = [],
        private readonly array $cookies = [],
    ) {
        $this->query = self::clean($query);
        $this->body = self::clean($body);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private static function clean(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return []; // absurdly nested input is an attack, not a form
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $key = is_string($key) ? str_replace("\0", '', $key) : $key;

            if (is_array($value)) {
                $clean[$key] = self::clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $value = mb_scrub(str_replace("\0", '', $value), 'UTF-8');
                $clean[$key] = in_array($key, self::NO_TRIM, true) ? $value : trim($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public static function capture(): self
    {
        $body = $_POST;

        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($json) ? $json : [];
        }

        return new self($_GET, $body, $_SERVER, $_FILES, $_COOKIE);
    }

    /** HTTP method, honouring a hidden _method field so HTML forms can send PUT/PATCH/DELETE. */
    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST' && isset($this->body['_method'])) {
            $spoofed = strtoupper((string) $this->body['_method']);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }

        return $method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * The folder the app is served from, e.g. "/MVC Models/framework" on XAMPP or "" on a real domain.
     * Detected automatically so no URL is ever hard-coded.
     */
    public function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        $base = rtrim(str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/'))), '/');

        // Root .htaccess rewrites into /public, but the visitor's URL doesn't contain it.
        if ($base !== '' && stripos($this->rawPath(), $base) !== 0 && str_ends_with($base, '/public')) {
            $base = substr($base, 0, -strlen('/public'));
        }

        return $this->basePath = $base;
    }

    /** Path relative to the app root, always starting with "/": "/users/5". */
    public function path(): string
    {
        $path = $this->rawPath();
        $base = $this->basePath();

        if ($base !== '' && stripos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        return '/' . trim($path, '/');
    }

    private function rawPath(): string
    {
        $path = parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return rawurldecode(is_string($path) ? $path : '/');
    }

    /** App-relative path plus query string: "/users?page=2". */
    public function pathWithQuery(): string
    {
        $query = (string) ($this->server['QUERY_STRING'] ?? '');

        return $this->path() . ($query !== '' ? '?' . $query : '');
    }

    /** Value from the request body, falling back to the query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** Present and not an empty string. */
    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_INT);

        return $value === false ? $default : $value;
    }

    /** "1", "true", "on", "yes" -> true */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        return $value === null ? $default : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * Validate this request's input (files included). Returns only validated fields.
     *
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = [], array $attributes = []): array
    {
        $data = $this->all();
        foreach (array_keys($rules) as $field) {
            if (($file = $this->file($field)) !== null) {
                $data[$field] = $file;
            }
        }

        return Validator::make($data, $rules, $messages, $attributes)->validate();
    }

    /** An uploaded file, or null when nothing was uploaded for this field. */
    public function file(string $key): ?Http\UploadedFile
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || is_array($file['name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new Http\UploadedFile($file, (bool) ($file['test'] ?? false));
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key)?->isValid() ?? false;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /** Token from "Authorization: Bearer <token>". */
    public function bearerToken(): ?string
    {
        $header = (string) $this->header('Authorization', '');

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    /** The signed-in user (session or API token). */
    public function user(): ?Model
    {
        return Auth::user();
    }

    public function userAgent(): string
    {
        return (string) $this->header('User-Agent', '');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $this->server[$key] ?? match (strtolower($name)) {
            'content-type' => $this->server['CONTENT_TYPE'] ?? $default,
            default => $default,
        };
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    /** True when the client expects JSON back (AJAX/fetch or Accept: application/json). */
    public function wantsJson(): bool
    {
        return $this->isAjax()
            || str_contains((string) $this->header('Accept'), 'application/json')
            || str_starts_with($this->path(), '/api/');
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (int) ($this->server['SERVER_PORT'] ?? 80) === 443
            || ($this->fromTrustedProxy() && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }

    /**
     * Client IP.
     *
     * X-Forwarded-For is only read when the request comes from a proxy in security.trusted_proxies.
     * The chain is then walked from the RIGHT (the end our own proxies append to): every trusted
     * proxy is skipped and the first untrusted address is the client. Values a client puts on the
     * left of the header are therefore never believed, even when a proxy appends instead of replacing.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        if (!$this->isTrustedProxy($remote) || empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', (string) $this->server['HTTP_X_FORWARDED_FOR'])));
        $candidate = $remote;

        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break; // malformed entry: stop at the last address we could verify
            }
            $candidate = $address;
            if (!$this->isTrustedProxy($address)) {
                return $address;
            }
        }

        return $candidate;
    }

    private function fromTrustedProxy(): bool
    {
        return $this->isTrustedProxy((string) ($this->server['REMOTE_ADDR'] ?? ''));
    }

    /** Exact IPs, CIDR ranges ("10.0.0.0/8", "2400:cb00::/32") or "*" from security.trusted_proxies. */
    private function isTrustedProxy(string $ip): bool
    {
        foreach ((array) Config::get('security.trusted_proxies', []) as $trusted) {
            $trusted = trim((string) $trusted);
            if ($trusted === '*' || $trusted === $ip || (str_contains($trusted, '/') && self::ipInRange($ip, $trusted))) {
                return true;
            }
        }

        return false;
    }

    public static function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /** @param array<string, string> $params Route parameters such as {id}. */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    /** Is the current path one of these patterns? $request->is('admin/*') */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->path(), '/');
        foreach ($patterns as $pattern) {
            if (Support\Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    public function routeIs(string ...$names): bool
    {
        $current = App::instance()->router->currentRouteName();
        foreach ($names as $name) {
            if ($current !== null && Support\Str::is($name, $current)) {
                return true;
            }
        }

        return false;
    }
}
