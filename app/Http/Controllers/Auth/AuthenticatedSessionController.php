<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Core\Auth;
use Core\Log;
use Core\Request;
use Core\Response;
use Core\Session;

final class AuthenticatedSessionController extends Controller
{
    /** How long a half-finished login (password OK, waiting for the 2FA code) stays valid. */
    private const CHALLENGE_SECONDS = 300;

    public function create(): Response
    {
        return $this->view('auth.login');
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        $email = strtolower((string) $data['email']);

        if (($seconds = Auth::lockoutSeconds($email)) > 0) {
            return $this->back()
                ->withErrors(['email' => __('auth.throttle', ['minutes' => (int) ceil($seconds / 60)])])
                ->withInput(['email' => $email]);
        }

        $user = Auth::attemptCredentials(['email' => $email, 'password' => (string) $data['password']]);
        if ($user === null) {
            // Same message whether the email or the password was wrong (no account enumeration).
            return $this->back()->withErrors(['email' => __('auth.failed')])->withInput(['email' => $email]);
        }

        // Second step: users with two-factor authentication must enter a code before they are signed in.
        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            Session::regenerate();
            Session::set('_2fa', ['id' => $user->getKey(), 'remember' => $request->boolean('remember'), 'expires' => time() + self::CHALLENGE_SECONDS]);

            return $this->redirectToRoute('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        Log::security('login_success');

        return Response::intended((string) config('auth.home_route', '/'))->with('success', __('auth.welcome_back'));
    }

    public function destroy(): Response
    {
        Auth::logout();

        return $this->redirect('/')->with('success', __('auth.logged_out'));
    }
}
