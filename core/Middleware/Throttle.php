<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Log;
use Core\RateLimiter;
use Core\Request;
use Core\Response;

/**
 * Rate limiting: ->middleware('throttle:5,1') = at most 5 requests per 1 minute
 * per user (or per IP for guests) for that route.
 */
final class Throttle implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        $maxAttempts = max(1, (int) ($params[0] ?? 60));
        $decaySeconds = max(1, (int) ($params[1] ?? 1)) * 60;

        $who = Auth::id() !== null ? 'user:' . Auth::id() : 'ip:' . $request->ip();
        $key = 'throttle:' . $who . '|' . $request->method() . '|' . $request->path();

        $attempts = RateLimiter::hit($key, $decaySeconds);

        if ($attempts > $maxAttempts) {
            $retryAfter = max(1, RateLimiter::availableIn($key));
            if ($attempts === $maxAttempts + 1) {
                Log::security('rate_limited', ['limit' => $maxAttempts]);
            }
            throw new HttpException(429, "Too many attempts. Please try again in $retryAfter seconds.", [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $response = Response::from($next($request));

        return $response->withHeaders([
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => (string) max(0, $maxAttempts - $attempts),
        ]);
    }
}
