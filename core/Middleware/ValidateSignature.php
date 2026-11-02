<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\HttpException;
use Core\Log;
use Core\Request;
use Core\URL;

/**
 * ->middleware('signed'): the URL must have been created with URL::signedRoute() and not be altered or expired.
 */
final class ValidateSignature implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (!URL::hasValidSignature($request)) {
            Log::security('invalid_signature');
            throw new HttpException(403, 'This link is invalid or has expired.');
        }

        return $next($request);
    }
}
