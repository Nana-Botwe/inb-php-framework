<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Auth;
use Core\HttpException;
use Core\Log;
use Core\Middleware\Middleware;
use Core\Request;
use Core\Response;

/**
 * ->middleware('role:admin') or ->middleware('role:admin,editor')
 * Blocks everyone else with 403 and logs the attempt.
 */
final class RequireRole implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = Auth::user();

        if ($user === null) {
            return Response::redirect((string) config('auth.login_route', '/login'));
        }

        if (!method_exists($user, 'hasRole') || !$user->hasRole(...$roles)) {
            Log::security('forbidden', ['required_roles' => $roles]);
            throw new HttpException(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
