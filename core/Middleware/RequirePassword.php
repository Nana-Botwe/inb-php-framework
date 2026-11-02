<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Config;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * ->middleware('password.confirm'): the user must have re-entered their password recently
 * (config auth.password_timeout, default 15 minutes) — "sudo mode" for sensitive pages.
 */
final class RequirePassword implements Middleware
{
    public const SESSION_KEY = '_password_confirmed_at';

    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        $timeout = (int) ($params[0] ?? Config::get('auth.password_timeout', 900));
        $confirmedAt = (int) Session::get(self::SESSION_KEY, 0);

        if ($confirmedAt > 0 && time() - $confirmedAt <= $timeout) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw new HttpException(423, 'Please confirm your password first.');
        }

        if ($request->method() === 'GET') {
            Session::set('_intended', $request->pathWithQuery());
        }

        return Response::redirect(route('password.confirm'));
    }
}
