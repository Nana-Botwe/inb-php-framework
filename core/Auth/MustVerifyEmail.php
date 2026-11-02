<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Mail\Mail;
use Core\URL;

/**
 * Add to the User model to require email verification (with the "verified" middleware).
 * Needs an email_verified_at column.
 */
trait MustVerifyEmail
{
    public function hasVerifiedEmail(): bool
    {
        return ($this->getAttributes()['email_verified_at'] ?? null) !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['email_verified_at' => date('Y-m-d H:i:s')])->save();
    }

    /** Signed link valid for 60 minutes; the hash ties it to the current email address. */
    public function verificationUrl(): string
    {
        return URL::temporarySignedRoute('verification.verify', 60, [
            'id' => $this->getKey(),
            'hash' => sha1((string) $this->email),
        ]);
    }

    public function sendEmailVerificationNotification(): void
    {
        Mail::to((string) $this->email)->send(new \App\Mail\VerifyEmail($this));
    }
}
