<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Request;
use Core\Response;

/**
 * Code that runs before (and/or after) a route's controller.
 * Return $next($request) to continue, or return your own Response to stop.
 */
interface Middleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string ...$params): mixed;
}
