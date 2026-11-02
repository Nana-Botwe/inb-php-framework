<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Log;
use Core\Request;
use Core\Response;

/**
 * ->middleware('two-factor'): users whose role is in auth.two_factor.required_roles (admins by default)
 * must have two-factor authentication switched on before they can continue.
 */
final class EnsureTwoFactorEnabled implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        $user = Auth::user();

        if ($user !== null && method_exists($user, 'requiresTwoFactor') && $user->requiresTwoFactor() && !$user->hasTwoFactorEnabled()) {
            Log::security('two_factor_required_blocked');

            if ($request->wantsJson()) {
                throw new HttpException(403, 'Two-factor authentication must be enabled for this account.');
            }

            return Response::redirect(route('profile.edit') . '#two-factor')
                ->with('warning', 'For your security, administrators must turn on two-factor authentication first.');
        }

        return $next($request);
    }
}
