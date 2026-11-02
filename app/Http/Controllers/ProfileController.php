<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Core\Auth;
use Core\Auth\Credentials;
use Core\Request;
use Core\Response;
use Core\Storage;

final class ProfileController extends Controller
{
    public function edit(): Response
    {
        return $this->view('profile.edit', ['user' => Auth::user()]);
    }

    public function update(Request $request): Response
    {
        $user = Auth::user();
        $emailChanged = strtolower(trim((string) $request->input('email'))) !== $user->email;

        $data = $request->validate([
            'name' => 'required|string|max:100|no_html',
            'email' => 'required|email|max:255|unique:users,email,' . $user->getKey(),
            // Changing the sign-in address is sensitive: require the current password.
            'email_current_password' => $emailChanged ? 'required|current_password' : 'nullable',
        ], ['email_current_password.required' => 'Enter your current password to change your email address.', 'email_current_password.current_password' => 'The password is incorrect.']);

        $user->fill(['name' => $data['name'], 'email' => strtolower((string) $data['email'])]);
        if ($emailChanged) {
            $user->email_verified_at = null; // new address must be verified again
        }
        $user->save();

        if ($emailChanged && config('auth.verify_email', true)) {
            $user->sendEmailVerificationNotification();
        }

        return $this->back()->with('success', 'Profile updated.');
    }

    public function avatar(Request $request): Response
    {
        $request->validate(['avatar' => 'required|image|max:5120']);

        $user = Auth::user();
        $file = $request->file('avatar');
        // Re-encoded to WebP, resized and stripped of metadata by the upload pipeline.
        $path = $file->store('avatars', 'public');

        if ($user->avatar) {
            Storage::disk('public')->delete((string) $user->avatar);
        }
        $user->forceFill(['avatar' => $path])->save();

        $stats = $file->compressionStats();
        $saved = $stats ? max(0, 100 - (int) round($stats['size'] / max(1, $stats['original_size']) * 100)) : 0;

        return $this->back()->with('success', "Photo updated ({$saved}% smaller after optimisation).");
    }

    public function password(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|strong_password|max:72|confirmed|different:current_password',
        ]);

        // Revokes other sessions, remember-me cookies and API tokens; this device stays signed in.
        Credentials::changePassword(Auth::user(), (string) $data['password'], keepCurrentSession: true);

        return $this->back()->with('success', 'Password changed. You have been signed out on all other devices, and your API tokens were revoked.');
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['password_delete' => 'required']);

        $user = Auth::user();
        if (!\Core\Hash::check((string) $request->input('password_delete'), (string) $user->getAttributes()['password'])) {
            return $this->back()->withErrors(['password_delete' => 'The password is incorrect.']);
        }

        if ($user->avatar) {
            Storage::disk('public')->delete((string) $user->avatar);
        }
        $user->revokeTokens();
        Auth::logout();
        $user->delete();

        return $this->redirect('/')->with('success', 'Your account has been deleted.');
    }
}
