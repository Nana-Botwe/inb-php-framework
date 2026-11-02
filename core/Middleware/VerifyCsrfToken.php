<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Config;
use Core\Csrf;
use Core\HttpException;
use Core\Log;
use Core\Request;

/**
 * Rejects POST/PUT/PATCH/DELETE requests that don't carry the session's CSRF token
 * (form field "_token" or header "X-CSRF-TOKEN"), and — as a second layer — requests whose
 * Origin/Referer header points to another site.
 */
final class VerifyCsrfToken implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $this->isExcepted($request)) {
            return $next($request);
        }

        if (Config::get('security.check_origin', true) && !$this->sameOrigin($request)) {
            Log::security('csrf_origin_mismatch', ['origin' => (string) ($request->header('Origin') ?? $request->header('Referer'))]);
            throw new HttpException(419, 'This request came from another website and was blocked.');
        }

        $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');

        if (!Csrf::verify($token)) {
            Log::security('csrf_token_mismatch');
            throw new HttpException(419, 'Your session has expired. Please refresh the page and try again.');
        }

        return $next($request);
    }

    /** When the browser sends Origin (or Referer), its host must be ours. */
    private function sameOrigin(Request $request): bool
    {
        $source = $request->header('Origin') ?? $request->header('Referer');
        if ($source === null || $source === '' || $source === 'null') {
            return true; // not sent (older browsers, privacy settings): rely on the token
        }

        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        $sourcePort = parse_url($source, PHP_URL_PORT);
        $host = strtolower((string) $request->server('HTTP_HOST', ''));

        return $sourceHost . ($sourcePort !== null ? ':' . $sourcePort : '') === $host
            || ($sourcePort === null && $sourceHost === explode(':', $host)[0]);
    }

    private function isExcepted(Request $request): bool
    {
        foreach ((array) Config::get('middleware.csrf_except', []) as $pattern) {
            if ($request->is((string) $pattern)) {
                return true;
            }
        }

        return false;
    }
}
