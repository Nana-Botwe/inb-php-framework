<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Core\Mail\Mailable;

final class VerifyEmail extends Mailable
{
    public function __construct(private readonly User $user)
    {
    }

    public function build(): void
    {
        $this->subject('Confirm your email address')->view('emails.verify-email', [
            'user' => $this->user,
            'url' => $this->user->verificationUrl(),
            'subject' => 'Confirm your email address',
        ]);
    }
}
