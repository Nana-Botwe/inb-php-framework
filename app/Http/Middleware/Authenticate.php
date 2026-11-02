<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Middleware\Middleware;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * ->middleware('auth')      signed-in users only (session)
 * ->middleware('auth:api')  requests with a valid API token (Authorization: Bearer ...)
 */
final class Authenticate implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (($params[0] ?? 'web') === 'api') {
            if (Auth::authenticateToken($request->bearerToken()) === null) {
                throw new HttpException(401, 'A valid API token is required.', ['WWW-Authenticate' => 'Bearer']);
            }

            return $next($request);
        }

        if (Auth::check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw new HttpException(401);
        }

        // Remember where they were going so login can send them back (internal paths only).
        if ($request->method() === 'GET') {
            Session::set('_intended', $request->pathWithQuery());
        }

        return Response::redirect((string) config('auth.login_route', '/login'))
            ->with('warning', 'Please sign in to continue.');
    }
}
