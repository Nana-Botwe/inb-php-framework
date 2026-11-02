<?php

declare(strict_types=1);

namespace Core;

/**
 * Signed URLs: links that can't be altered (e.g. email verification, unsubscribe links).
 *
 *   URL::signedRoute('verification.verify', ['id' => 5, 'hash' => sha1($email)], 60);  // valid 60 min
 *   ->middleware('signed')   on the route checks the signature.
 */
final class URL
{
    /** @param array<string, mixed> $params */
    public static function signedRoute(string $name, array $params = [], ?int $minutes = null, bool $absolute = true): string
    {
        if ($minutes !== null) {
            $params['expires'] = time() + $minutes * 60;
        }

        $path = App::instance()->router->url($name, $params);
        $separator = str_contains($path, '?') ? '&' : '?';
        $path .= $separator . 'signature=' . Crypt::hmac(self::canonical($path));

        return $absolute ? self::to($path) : url($path);
    }

    /** @param array<string, mixed> $params */
    public static function temporarySignedRoute(string $name, int $minutes, array $params = []): string
    {
        return self::signedRoute($name, $params, $minutes);
    }

    public static function hasValidSignature(Request $request): bool
    {
        $query = $request->queryAll();
        $signature = (string) ($query['signature'] ?? '');
        unset($query['signature']);

        $path = $request->path() . ($query === [] ? '' : '?' . http_build_query($query));

        if ($signature === '' || !Crypt::verifyHmac(self::canonical($path), $signature)) {
            return false;
        }

        return !isset($query['expires']) || (int) $query['expires'] >= time();
    }

    /** Absolute URL using APP_URL (needed in emails), falling back to the current host. */
    public static function to(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($base === '') {
            // Never build email links from an unvalidated Host header on a live site.
            if (Config::get('app.env') === 'production') {
                throw new \RuntimeException('APP_URL must be set in production (it is used for links in emails).');
            }

            $request = App::instance()->request();
            $scheme = $request?->isSecure() ? 'https' : 'http';
            $host = (string) ($request?->server('HTTP_HOST') ?? 'localhost');
            if (!Http\TrustedHosts::isAllowed($host)) {
                $host = 'localhost';
            }

            return $scheme . '://' . $host . url($path);
        }

        $basePath = (string) parse_url($base, PHP_URL_PATH);
        $origin = substr($base, 0, strlen($base) - strlen($basePath));
        $encodedBase = implode('/', array_map('rawurlencode', explode('/', rawurldecode($basePath))));

        return $origin . $encodedBase . '/' . ltrim($path, '/');
    }

    private static function canonical(string $path): string
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);
        ksort($query);

        return ($parts['path'] ?? '/') . '?' . http_build_query($query);
    }
}
