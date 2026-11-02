<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Config;

/**
 * Which Host headers the app accepts. Prevents "Host header poisoning", where an attacker sends a
 * fake Host so that links in password-reset or verification emails point to their own site.
 *
 * List = TRUSTED_HOSTS, or else the host of APP_URL. In APP_ENV=local, localhost/127.0.0.1/::1 are added.
 * Wildcards: "*.example.com" matches sub-domains (not example.com itself).
 */
final class TrustedHosts
{
    /** @return string[] lower-case host names / patterns; empty = no restriction (only possible without APP_URL outside production) */
    public static function list(): array
    {
        $hosts = array_map('strtolower', (array) Config::get('app.trusted_hosts', []));

        if ($hosts === []) {
            $appHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
            if (is_string($appHost) && $appHost !== '') {
                $hosts[] = strtolower($appHost);
            }
        }

        if ($hosts !== [] && in_array(Config::get('app.env'), ['local', 'testing'], true)) {
            array_push($hosts, 'localhost', '127.0.0.1', '::1');
        }

        return array_values(array_unique($hosts));
    }

    public static function isAllowed(string $hostHeader): bool
    {
        $host = self::hostOnly($hostHeader);
        if ($host === '' || !preg_match('/^[a-z0-9.\-:\[\]]+$/', $host)) {
            return false;
        }

        $allowed = self::list();
        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $pattern) {
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
                return true;
            }
        }

        return false;
    }

    /** "Example.com:8080" -> "example.com", "[::1]:80" -> "::1" */
    public static function hostOnly(string $hostHeader): string
    {
        $host = strtolower(trim($hostHeader));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? '' : substr($host, 1, $end - 1);
        }

        return substr_count($host, ':') === 1 ? (string) strstr($host, ':', true) : $host;
    }
}
