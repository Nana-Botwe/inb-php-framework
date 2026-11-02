<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * API tokens for mobile apps / other servers.
 *
 *   POST /api/tokens  {email, password, device_name}  ->  {"token": "12|AbC..."}   (shown once)
 *   GET  /api/user    Authorization: Bearer 12|AbC...
 *   DELETE /api/tokens/current
 */
final class TokenController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
            'device_name' => 'required|string|max:100|no_html',
        ]);

        if (Auth::lockoutSeconds((string) $data['email']) > 0) {
            throw new HttpException(429, 'Too many failed attempts. Try again later.');
        }

        $user = Auth::validateCredentials(['email' => strtolower((string) $data['email']), 'password' => (string) $data['password']]);
        if ($user === null) {
            \Core\RateLimiter::hit('login:' . strtolower((string) $data['email']) . '|' . $request->ip(), 900);
            throw new HttpException(401, __('auth.failed'));
        }

        $expires = (new \DateTimeImmutable())->modify('+' . (int) config('auth.token_days', 90) . ' days');
        $token = $user->createToken((string) $data['device_name'], ['*'], $expires);

        return $this->json(['token' => $token->plainTextToken, 'expires_at' => $expires->format(DATE_ATOM)], 201);
    }

    public function user(): Response
    {
        return $this->json(['data' => Auth::user()]);
    }

    public function destroy(): Response
    {
        Auth::token()?->delete();

        return Response::noContent();
    }
}
