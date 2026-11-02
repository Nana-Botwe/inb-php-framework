<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Core\Mail\Mailable;

final class WelcomeMail extends Mailable
{
    public function __construct(private readonly User $user)
    {
    }

    public function build(): void
    {
        $this->subject('Welcome to ' . config('app.name'))->view('emails.welcome', [
            'user' => $this->user,
            'url' => route('dashboard', [], true),
            'subject' => 'Welcome to ' . config('app.name'),
        ]);
    }
}
