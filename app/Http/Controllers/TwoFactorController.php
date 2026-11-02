<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Core\Auth;
use Core\Auth\Credentials;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Two-factor settings on the profile page. All routes require a recently confirmed password.
 */
final class TwoFactorController extends Controller
{
    /** Start setup: new secret + recovery codes (shown once). */
    public function store(): Response
    {
        $codes = Auth::user()->enableTwoFactor();
        Session::flash('two_factor_recovery_codes', $codes);

        return $this->redirect(route('profile.edit') . '#two-factor')->with('info', 'Scan the key with your authenticator app, then enter the 6-digit code to finish.');
    }

    public function confirm(Request $request): Response
    {
        $data = $request->validate(['code' => ['required', 'regex:/^\d{6}$/']]);

        if (!Auth::user()->confirmTwoFactor((string) $data['code'])) {
            return $this->redirect(route('profile.edit') . '#two-factor')->withErrors(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        return $this->redirect(route('profile.edit') . '#two-factor')->with('success', 'Two-factor authentication is now on.');
    }

    public function recoveryCodes(): Response
    {
        Session::flash('two_factor_recovery_codes', Auth::user()->regenerateRecoveryCodes());

        return $this->redirect(route('profile.edit') . '#two-factor')->with('success', 'New recovery codes created. The old ones no longer work.');
    }

    public function destroy(): Response
    {
        $user = Auth::user();
        if ($user->requiresTwoFactor()) {
            return $this->redirect(route('profile.edit') . '#two-factor')->withErrors(['code' => 'Your role requires two-factor authentication, so it cannot be turned off.']);
        }

        $user->disableTwoFactor();

        return $this->redirect(route('profile.edit') . '#two-factor')->with('success', 'Two-factor authentication is off.');
    }

    /** "Sign out of all other devices" — revokes other sessions, remember-me and API tokens. */
    public function logoutOtherDevices(): Response
    {
        Credentials::revoke(Auth::user(), 'logout_other_devices', keepCurrentSession: true);

        return $this->back()->with('success', 'You have been signed out on every other device.');
    }
}
