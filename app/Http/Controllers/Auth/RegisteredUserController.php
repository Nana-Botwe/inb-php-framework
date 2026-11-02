<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Core\Auth;
use Core\Request;
use Core\Response;

final class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return $this->view('auth.register');
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|no_html',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|strong_password|max:72|confirmed',
            'terms' => 'accepted',
        ], [], ['terms' => 'terms and conditions']);

        // The "hashed" cast on User hashes the password automatically.
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower((string) $data['email']),
            'password' => $data['password'],
        ]);

        Auth::login($user);
        event('user.registered', [$user]);

        if (config('auth.verify_email', true)) {
            $user->sendEmailVerificationNotification();

            return $this->redirectToRoute('verification.notice')->with('success', __('auth.registered_verify'));
        }

        dispatch(new SendWelcomeEmail((int) $user->id));

        return $this->redirect((string) config('auth.home_route', '/'))->with('success', __('auth.registered'));
    }
}
