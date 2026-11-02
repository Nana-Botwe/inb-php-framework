<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Auth;
use Core\Middleware\Middleware;
use Core\Request;
use Core\Response;

/**
 * ->middleware('guest'): keeps signed-in users away from login/register pages.
 */
final class RedirectIfAuthenticated implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (Auth::check()) {
            return Response::redirect((string) config('auth.home_route', '/'));
        }

        return $next($request);
    }
}
