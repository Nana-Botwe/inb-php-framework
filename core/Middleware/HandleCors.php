<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Config;
use Core\Request;
use Core\Response;

/**
 * CORS for the API (config/cors.php). Only the listed origins may call the API from a browser.
 */
final class HandleCors implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        $origin = (string) $request->header('Origin', '');
        $allowed = $this->allowedOrigin($origin);

        if ($request->method() === 'OPTIONS') {
            $response = Response::noContent();
        } else {
            $response = Response::from($next($request));
        }

        if ($allowed === null) {
            return $response;
        }

        $response->withHeaders([
            'Access-Control-Allow-Origin' => $allowed,
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => implode(', ', (array) Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])),
            'Access-Control-Allow-Headers' => implode(', ', (array) Config::get('cors.allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'])),
            'Access-Control-Max-Age' => (string) Config::get('cors.max_age', 600),
        ]);

        if (Config::get('cors.supports_credentials', false) && $allowed !== '*') {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function allowedOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return null;
        }

        $origins = (array) Config::get('cors.allowed_origins', []);
        if (in_array('*', $origins, true)) {
            return '*';
        }

        return in_array($origin, $origins, true) ? $origin : null;
    }
}
