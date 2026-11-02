<?php

declare(strict_types=1);

namespace App\Mail;

use Core\Mail\Mailable;

/** Sent by "php console mail:test you@example.com" to preview the selected email template. */
final class TestMail extends Mailable
{
    public function build(): void
    {
        $this->subject('Test email from ' . config('app.name'))->view('emails.test', [
            'theme' => (string) config('mail.theme'),
            'subject' => 'Test email',
        ]);
    }
}
