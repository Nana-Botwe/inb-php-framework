<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Core\Auth\Password;
use Core\Request;
use Core\Response;

final class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return $this->view('auth.forgot-password');
    }

    public function email(Request $request): Response
    {
        $started = microtime(true);
        $data = $request->validate(['email' => 'required|email|max:255']);

        // The result (sent / unknown address / throttled) is deliberately ignored: every visitor
        // gets the same message, so this form can't be used to find out who has an account.
        Password::sendResetLink((string) $data['email']);

        // Equalise response time (sending mail takes longer than skipping an unknown address).
        // For best results also set QUEUE_CONNECTION=database so mail is sent in the background.
        $floor = (int) config('auth.passwords.response_floor_ms', 800) * 1000;
        $elapsed = (int) ((microtime(true) - $started) * 1_000_000);
        if ($floor > $elapsed) {
            usleep($floor - $elapsed);
        }

        return $this->back()->with('success', __('passwords.sent'));
    }

    public function edit(Request $request, string $token): Response
    {
        return $this->view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $request->validate([
            'token' => 'required|string|max:128',
            'email' => 'required|email|max:255',
            'password' => 'required|strong_password|max:72|confirmed',
        ]);

        $status = Password::reset((string) $data['email'], (string) $data['token'], (string) $data['password']);

        if ($status !== Password::PASSWORD_RESET) {
            return $this->back()->withErrors(['email' => __($status)])->withInput(['email' => $data['email']]);
        }

        return $this->redirectToRoute('login')->with('success', __('passwords.reset'));
    }
}
