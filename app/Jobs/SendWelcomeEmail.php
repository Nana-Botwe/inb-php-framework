<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\WelcomeMail;
use App\Models\User;
use Core\Mail\Mail;
use Core\Queue\Job;

/**
 * Example queued job: dispatch(new SendWelcomeEmail($user->id)).
 * With QUEUE_CONNECTION=database it runs in the background via "php console queue:work".
 */
final class SendWelcomeEmail extends Job
{
    public int $tries = 3;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user !== null) {
            Mail::to((string) $user->email)->send(new WelcomeMail($user));
        }
    }
}
