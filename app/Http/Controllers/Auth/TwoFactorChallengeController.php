<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Core\Auth;
use Core\Log;
use Core\RateLimiter;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Second login step for accounts with two-factor authentication.
 */
final class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): Response
    {
        if ($this->pendingUser() === null) {
            return $this->redirectToRoute('login');
        }

        return $this->view('auth.two-factor-challenge');
    }

    public function store(Request $request): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirectToRoute('login')->with('warning', 'Your sign-in expired. Please start again.');
        }

        $data = $request->validate(['code' => 'required|string|max:32']);

        // Per-account limit on top of the route throttle, so codes can't be guessed.
        $key = '2fa-challenge:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Session::forget('_2fa');
            Log::security('two_factor_locked', ['target_user' => $user->getKey()]);

            return $this->redirectToRoute('login')->withErrors(['email' => 'Too many wrong codes. Please sign in again in a few minutes.']);
        }

        if (!$user->verifyTwoFactor((string) $data['code'])) {
            RateLimiter::hit($key, 900);
            Log::security('two_factor_failed', ['target_user' => $user->getKey()]);

            return $this->back()->withErrors(['code' => 'That code is not valid. Try the current code from your app, or a recovery code.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) (Session::get('_2fa')['remember'] ?? false);
        Session::forget('_2fa');

        Auth::login($user, $remember);
        Log::security('login_success', ['two_factor' => true]);

        return \Core\Response::intended((string) config('auth.home_route', '/'))->with('success', __('auth.welcome_back'));
    }

    private function pendingUser(): ?User
    {
        $pending = Session::get('_2fa');
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            Session::forget('_2fa');

            return null;
        }

        $user = User::find($pending['id'] ?? 0);

        return $user instanceof User && $user->hasTwoFactorEnabled() ? $user : null;
    }
}
