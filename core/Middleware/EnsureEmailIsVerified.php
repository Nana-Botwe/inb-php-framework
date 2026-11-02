<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * ->middleware('verified'): users must confirm their email address first.
 */
final class EnsureEmailIsVerified implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        $user = Auth::user();

        if (!\Core\Config::get('auth.verify_email', true)) {
            return $next($request);
        }

        if ($user !== null && method_exists($user, 'hasVerifiedEmail') && !$user->hasVerifiedEmail()) {
            if ($request->wantsJson()) {
                throw new HttpException(403, 'Your email address is not verified.');
            }

            return Response::redirect(route('verification.notice'));
        }

        return $next($request);
    }
}
