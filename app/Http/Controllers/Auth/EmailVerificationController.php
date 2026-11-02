<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendWelcomeEmail;
use Core\Auth;
use Core\HttpException;
use Core\Response;

final class EmailVerificationController extends Controller
{
    public function notice(): Response
    {
        $user = Auth::user();
        if ($user !== null && $user->hasVerifiedEmail()) {
            return $this->redirect((string) config('auth.home_route', '/'));
        }

        return $this->view('auth.verify-email');
    }

    /** Signed link from the email (the "signed" middleware already checked the signature and expiry). */
    public function verify(string $id, string $hash): Response
    {
        $user = Auth::user();

        // The link must belong to the signed-in user and to their CURRENT email address.
        if ($user === null || (string) $user->getKey() !== $id || !hash_equals(sha1((string) $user->email), $hash)) {
            throw new HttpException(403, 'This verification link does not belong to your account.');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            dispatch(new SendWelcomeEmail((int) $user->id));
        }

        return $this->redirect((string) config('auth.home_route', '/'))->with('success', __('auth.verified'));
    }

    public function resend(): Response
    {
        $user = Auth::user();
        if ($user !== null && !$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return $this->back()->with('success', __('auth.verification_sent'));
    }
}
