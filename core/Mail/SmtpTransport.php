<?php

declare(strict_types=1);

namespace Core\Mail;

use RuntimeException;

/**
 * Minimal SMTP client: TLS (STARTTLS on 587) or SSL (465), AUTH LOGIN, with certificate checks on.
 * Works with Gmail (app password), Mailtrap, SendGrid, cPanel mail, etc.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array{raw: string, from: string, recipients: string[]} $message */
    public function send(array $message): void
    {
        $host = (string) ($this->config['host'] ?? 'localhost');
        $port = (int) ($this->config['port'] ?? 587);
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $timeout = (int) ($this->config['timeout'] ?? 15);

        $context = stream_context_create(['ssl' => [
            'verify_peer' => (bool) ($this->config['verify_peer'] ?? true),
            'verify_peer_name' => (bool) ($this->config['verify_peer'] ?? true),
        ]]);

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("SMTP connection to $host:$port failed: $error");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect(220);
            $domain = (string) ($this->config['local_domain'] ?? 'localhost');
            $this->command("EHLO $domain", 250);

            if ($encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('Could not start TLS encryption.');
                }
                $this->command("EHLO $domain", 250);
            }

            $username = (string) ($this->config['username'] ?? '');
            if ($username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($username), 334);
                $this->command(base64_encode((string) ($this->config['password'] ?? '')), 235);
            }

            $this->command('MAIL FROM:<' . $message['from'] . '>', 250);
            foreach ($message['recipients'] as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', 354);
            // Dot-stuffing: a line starting with "." must be doubled.
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message['raw']));
            $this->write(str_replace("\n", "\r\n", (string) $data) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    /** @param int|int[] $expected */
    private function command(string $command, int|array $expected): void
    {
        $this->write($command);
        $this->expect($expected);
    }

    private function write(string $data): void
    {
        fwrite($this->socket, $data . "\r\n");
    }

    /** @param int|int[] $expected */
    private function expect(int|array $expected): void
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
    }
}
