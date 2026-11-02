<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\App;
use Core\Config;
use Core\Log;
use Core\Queue\Queue;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mail facade. Driver is set by MAIL_MAILER in .env: log (default, writes to storage/logs/mail.log),
 * smtp, or array (keeps messages in memory — for tests).
 *
 *   Mail::to('ama@example.com')->send(new WelcomeMail($user));
 *   Mail::to('ama@example.com')->queue(new WelcomeMail($user));    // in the background (see queue())
 *   Mail::raw('ama@example.com', 'Subject', 'Plain text body');
 */
final class Mail
{
    /** @var array<int, array<string, mixed>> Messages sent with the "array" driver. */
    public static array $sent = [];

    /** @var array<int, array{address: string, name: string}> */
    private array $recipients = [];

    /** @var array<int, array{address: string, name: string}> */
    private array $ccList = [];

    /** @var array<int, array{address: string, name: string}> */
    private array $bccList = [];

    public static function to(string|array $address, string $name = ''): self
    {
        $mail = new self();
        foreach ((array) $address as $value) {
            $mail->recipients[] = ['address' => $value, 'name' => is_array($address) ? '' : $name];
        }

        return $mail;
    }

    public function cc(string $address, string $name = ''): self
    {
        $this->ccList[] = ['address' => $address, 'name' => $name];

        return $this;
    }

    public function bcc(string $address, string $name = ''): self
    {
        $this->bccList[] = ['address' => $address, 'name' => $name];

        return $this;
    }

    public static function raw(string $to, string $subject, string $text): void
    {
        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };
        $mailable->subject($subject)->text($text);
        self::to($to)->send($mailable);
    }

    /** Send now: the caller waits for the mail server, and delivery errors are thrown to the caller. */
    public function send(Mailable $mailable): void
    {
        self::deliver($this->compose($mailable), $mailable->subject, $mailable);
    }

    /**
     * Send in the background — the response never waits for, or changes because of, the mail server:
     *  - QUEUE_CONNECTION=database (recommended in production): stored encrypted in the jobs table and
     *    delivered by "php console queue:work", with retries
     *  - QUEUE_CONNECTION=sync: delivered after the response has been sent (on PHP-FPM / LiteSpeed the
     *    visitor's connection is already closed). Errors are logged, never shown.
     *
     * The message is rendered now, so links, translations and data are those of the current request.
     */
    public function queue(Mailable $mailable): void
    {
        $job = SendQueuedMail::for($this->compose($mailable), $mailable->subject);

        if (Config::get('queue.default', 'sync') !== 'sync') {
            Queue::push($job);

            return;
        }

        App::instance()->afterResponse(static fn () => $job->handle());
    }

    /**
     * Hand a built message to the configured driver.
     *
     * @param array{raw: string, from: string, recipients: string[]} $message
     */
    public static function deliver(array $message, string $subject = '', ?Mailable $mailable = null): void
    {
        $driver = (string) Config::get('mail.default', 'log');

        match ($driver) {
            'log' => self::writeLog($message),
            'array' => self::$sent[] = ['mailable' => $mailable, 'subject' => $subject, 'raw' => $message['raw'], 'to' => $message['recipients']],
            'smtp' => (new SmtpTransport((array) Config::get('mail.mailers.smtp', [])))->send($message),
            default => throw new RuntimeException("Unsupported mail driver [$driver]."),
        };

        Log::info('mail_sent', ['subject' => $subject, 'to' => count($message['recipients'])]);
    }

    /** @return array{raw: string, from: string, recipients: string[]} */
    private function compose(Mailable $mailable): array
    {
        $mailable->build();
        $mailable->to = [...$mailable->to, ...$this->recipients];
        $mailable->cc = [...$mailable->cc, ...$this->ccList];
        $mailable->bcc = [...$mailable->bcc, ...$this->bccList];

        foreach ([...$mailable->to, ...$mailable->cc, ...$mailable->bcc] as $recipient) {
            self::assertAddress($recipient['address']);
        }

        return (new MessageBuilder())->build($mailable);
    }

    /** Tests: messages sent to an address with the array driver. @return array<int, array<string, mixed>> */
    public static function sentTo(string $address): array
    {
        return array_values(array_filter(self::$sent, static fn ($m) => in_array($address, $m['to'], true)));
    }

    /** Rejects bad addresses and CR/LF (header injection). */
    public static function assertAddress(string $address): void
    {
        if (preg_match('/[\r\n]/', $address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
    }

    /** @param array{raw: string, recipients: string[]} $message */
    private static function writeLog(array $message): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/mail.log', str_repeat('=', 70) . "\n" . date('Y-m-d H:i:s') . "\n" . $message['raw'] . "\n\n", FILE_APPEND | LOCK_EX);
    }
}
