<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Config;

/**
 * Builds a MIME message (headers + multipart body) from a Mailable.
 */
final class MessageBuilder
{
    /** @return array{raw: string, from: string, recipients: string[]} */
    public function build(Mailable $mail): array
    {
        $fromAddress = (string) Config::get('mail.from.address', 'hello@example.com');
        $fromName = (string) Config::get('mail.from.name', 'App');
        Mail::assertAddress($fromAddress);

        $html = $mail->renderHtml();
        $text = $mail->text ?? ($html !== null ? $this->htmlToText($html) : '');

        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => $this->address($fromAddress, $fromName),
            'To' => implode(', ', array_map(fn ($r) => $this->address($r['address'], $r['name']), $mail->to)),
            'Subject' => $this->encode($mail->subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . (explode('@', $fromAddress)[1] ?? 'localhost') . '>',
            'MIME-Version' => '1.0',
        ];
        if ($mail->cc !== []) {
            $headers['Cc'] = implode(', ', array_map(fn ($r) => $this->address($r['address'], $r['name']), $mail->cc));
        }
        if ($mail->replyTo !== null) {
            $headers['Reply-To'] = $this->address($mail->replyTo['address'], $mail->replyTo['name']);
        }

        $altBoundary = 'alt_' . bin2hex(random_bytes(8));
        $body = $html === null
            ? $this->part('text/plain', $text, false)
            : "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n"
                . "--$altBoundary\r\n" . $this->part('text/plain', $text) . "\r\n"
                . "--$altBoundary\r\n" . $this->part('text/html', $html) . "\r\n"
                . "--$altBoundary--";

        if ($mail->attachments !== []) {
            $mixed = 'mix_' . bin2hex(random_bytes(8));
            $content = "Content-Type: multipart/mixed; boundary=\"$mixed\"\r\n\r\n--$mixed\r\n$body\r\n";
            foreach ($mail->attachments as $attachment) {
                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment['name']);
                $content .= "--$mixed\r\nContent-Type: application/octet-stream; name=\"$name\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n"
                    . chunk_split(base64_encode((string) file_get_contents($attachment['path']))) . "\r\n";
            }
            $body = $content . "--$mixed--";
        }

        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . str_replace(["\r", "\n"], '', $value) . "\r\n";
        }

        $recipients = array_map(static fn ($r) => $r['address'], [...$mail->to, ...$mail->cc, ...$mail->bcc]);

        return ['raw' => $raw . $body, 'from' => $fromAddress, 'recipients' => array_values(array_unique($recipients))];
    }

    private function part(string $type, string $content, bool $withSeparator = true): string
    {
        return "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $content));
    }

    private function address(string $address, string $name): string
    {
        return $name === '' ? $address : $this->encode($name) . ' <' . $address . '>';
    }

    /** RFC 2047 encoding for non-ASCII header text. */
    private function encode(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/h\d|/li|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
