<?php

declare(strict_types=1);

namespace App\Mail;

use Core\Mail\Mailable;
use Core\Model;

final class ResetPassword extends Mailable
{
    public function __construct(private readonly Model $user, private readonly string $token)
    {
    }

    public function build(): void
    {
        $this->subject('Reset your password')->view('emails.reset-password', [
            'user' => $this->user,
            'url' => route('password.reset', ['token' => $this->token, 'email' => (string) $this->user->email], true),
            'minutes' => (int) config('auth.passwords.expire', 60),
            'subject' => 'Reset your password',
        ]);
    }
}
