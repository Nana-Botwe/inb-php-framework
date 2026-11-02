<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Crypt;
use Core\Queue\Job;
use RuntimeException;

/**
 * A fully built email waiting to be delivered (created by Mail::to(...)->queue($mailable)).
 *
 * The message is stored ENCRYPTED (AES-256-GCM with APP_KEY): emails such as password resets contain
 * live links, and the jobs / failed_jobs tables must not become a place to steal them from.
 */
final class SendQueuedMail extends Job
{
    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public string $message = '')
    {
    }

    /** @param array{raw: string, from: string, recipients: string[]} $message */
    public static function for(array $message, string $subject): self
    {
        return new self(Crypt::encrypt(['message' => $message, 'subject' => $subject]));
    }

    public function handle(): void
    {
        $data = Crypt::decrypt($this->message);
        if (!is_array($data) || !is_array($data['message'] ?? null)) {
            throw new RuntimeException('Queued email could not be decrypted.');
        }

        Mail::deliver($data['message'], (string) ($data['subject'] ?? ''));
    }
}
