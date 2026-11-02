<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Core\Auth;
use Core\Hash;
use Core\Log;
use Core\Middleware\RequirePassword;
use Core\RateLimiter;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * "Please confirm your password" before sensitive actions (admin area, two-factor settings ...).
 */
final class ConfirmPasswordController extends Controller
{
    public function show(): Response
    {
        return $this->view('auth.confirm-password');
    }

    public function store(Request $request): Response
    {
        $request->validate(['password' => 'required|string|max:255']);
        $user = Auth::user();
        $key = 'confirm-password:' . $user->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->back()->withErrors(['password' => 'Too many attempts. Please wait a few minutes.']);
        }

        if (!Hash::check((string) $request->input('password'), (string) $user->getAttributes()['password'])) {
            RateLimiter::hit($key, 900);
            Log::security('password_confirm_failed');

            return $this->back()->withErrors(['password' => 'The password is incorrect.']);
        }

        RateLimiter::clear($key);
        Session::set(RequirePassword::SESSION_KEY, time());

        return Response::intended((string) config('auth.home_route', '/'));
    }
}
