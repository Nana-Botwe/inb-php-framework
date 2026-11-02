<?php

declare(strict_types=1);

namespace Core;

/**
 * Inspects EVERY request before routing and blocks:
 *   - banned / blocklisted IP addresses
 *   - dangerous HTTP methods (TRACE, TRACK, CONNECT, ...)
 *   - oversized URLs and control characters / null bytes
 *   - path traversal (../), and probes for secret files (.env, .git, backups, config, logs)
 *   - common SQL injection, XSS and PHP code-injection payloads in the URL and query string
 *   - known attack-tool user agents (sqlmap, nikto, ...)
 *
 * Every block is written to the security log. An IP that is blocked too often is banned for a while.
 * Rules live in config/firewall.php.
 */
final class Firewall
{
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public static function inspect(Request $request): void
    {
        if (!Config::get('firewall.enabled', true)) {
            return;
        }

        $ip = $request->ip();

        if (in_array($ip, (array) Config::get('firewall.allow_ips', []), true)) {
            return;
        }

        if (in_array($ip, (array) Config::get('firewall.block_ips', []), true)) {
            self::block($request, 'ip_blocklisted', false);
        }

        // Ban list fails OPEN: if its storage breaks, the site stays up (attack filters below still apply).
        if (RateLimiter::tooManyAttempts(self::banKey($ip), (int) Config::get('firewall.ban_after', 10), failOpen: true)) {
            throw new HttpException(403, 'Access denied.');
        }

        // Host header must be one of ours (stops Host-header poisoning of links and redirects).
        $host = (string) $request->server('HTTP_HOST', '');
        if ($host !== '' && !Http\TrustedHosts::isAllowed($host)) {
            Log::security('untrusted_host', ['host' => substr($host, 0, 100)]);
            throw new HttpException(400, 'This site is not available under that address.');
        }

        $method = strtoupper((string) $request->server('REQUEST_METHOD', 'GET'));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            self::block($request, 'method_not_allowed');
        }

        $uri = (string) $request->server('REQUEST_URI', '/');
        if (strlen($uri) > (int) Config::get('firewall.max_url_length', 2048)) {
            self::block($request, 'url_too_long', true, 414);
        }

        // Decode repeatedly so double-encoded payloads (%252e%252e) are caught too.
        $decoded = $uri;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        $decoded = str_replace('+', ' ', $decoded);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded)) {
            self::block($request, 'control_characters');
        }

        if (preg_match('#(\.\./|\.\.\\\\|/\.\.$)#', $decoded)) {
            self::block($request, 'path_traversal');
        }

        $path = strtolower($request->path());
        foreach ((array) Config::get('firewall.blocked_paths', []) as $pattern) {
            if (preg_match($pattern, $path)) {
                self::block($request, 'sensitive_path_probe', true, 404);
            }
        }

        foreach ((array) Config::get('firewall.attack_patterns', []) as $name => $pattern) {
            if (preg_match($pattern, $decoded)) {
                self::block($request, 'attack_pattern:' . $name);
            }
        }

        $agent = (string) $request->header('User-Agent', '');
        foreach ((array) Config::get('firewall.blocked_user_agents', []) as $bad) {
            if ($bad !== '' && stripos($agent, (string) $bad) !== false) {
                self::block($request, 'bad_user_agent');
            }
        }

        if (Config::get('firewall.scan_request_body', false)) {
            self::inspectBody($request);
        }
    }

    /** Optional: scan submitted form fields too (may flag legitimate text, so off by default). */
    private static function inspectBody(Request $request): void
    {
        $values = [];
        $input = $request->all();
        array_walk_recursive($input, static function ($value) use (&$values): void {
            if (is_string($value)) {
                $values[] = $value;
            }
        });
        $body = implode("\n", $values);

        foreach ((array) Config::get('firewall.attack_patterns', []) as $name => $pattern) {
            if (preg_match($pattern, $body)) {
                self::block($request, 'attack_pattern_body:' . $name);
            }
        }
    }

    private static function block(Request $request, string $reason, bool $countTowardsBan = true, int $status = 403): never
    {
        Log::security('firewall_block', [
            'reason' => $reason,
            'uri' => substr((string) $request->server('REQUEST_URI', ''), 0, 500),
            'user_agent' => substr((string) $request->header('User-Agent', ''), 0, 200),
        ]);

        if ($countTowardsBan) {
            $minutes = (int) Config::get('firewall.ban_minutes', 60);
            $hits = RateLimiter::hit(self::banKey($request->ip()), $minutes * 60, failOpen: true);
            if ($hits === (int) Config::get('firewall.ban_after', 10)) {
                Log::security('ip_banned', ['minutes' => $minutes]);
            }
        }

        throw new HttpException($status, $status === 404 ? '' : 'Your request was blocked for security reasons.');
    }

    private static function banKey(string $ip): string
    {
        return 'firewall:' . $ip;
    }
}
