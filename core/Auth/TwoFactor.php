<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Cache;
use Core\Token;

/**
 * Time-based one-time passwords (TOTP, RFC 6238) — works with Google Authenticator, Microsoft
 * Authenticator, Authy, 1Password, Bitwarden ...
 *
 * - 160-bit secrets, 6 digits, 30-second steps, ±1 step tolerance for clock drift
 * - constant-time comparison; each code can be used only once (replay protection)
 */
final class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** URI for authenticator apps (can be turned into a QR code). */
    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check a code. $replayKey identifies the user so the same code can't be used twice.
     */
    public static function verify(string $secret, string $code, string $replayKey = '', int $window = 1, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = self::base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $step = intdiv($time ?? time(), 30);
        $matchedStep = null;
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeAt($key, $step + $offset), $code)) {
                $matchedStep = $step + $offset;
            }
        }

        if ($matchedStep === null) {
            return false;
        }

        if ($replayKey !== '') {
            $cacheKey = '2fa-used:' . hash('sha256', $replayKey) . ':' . $matchedStep;
            if (!Cache::add($cacheKey, true, 120)) {
                return false; // already used
            }
        }

        return true;
    }

    /** The code for a time step (used by verify and by tests). */
    public static function codeAt(string $binaryKey, int $step): string
    {
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $binaryKey, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Current code for a base32 secret (tests / debugging). */
    public static function currentCode(string $secret, ?int $time = null): string
    {
        return self::codeAt(self::base32Decode($secret), intdiv($time ?? time(), 30));
    }

    /** @return array{plain: string[], hashed: string[]} Recovery codes like "k3J9a-x2PqW". */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $plain[] = \Core\Support\Str::random(5) . '-' . \Core\Support\Str::random(5);
        }

        return ['plain' => $plain, 'hashed' => array_map([Token::class, 'hash'], $plain)];
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper((string) preg_replace('/[\s=]+/', '', $data));
        if ($data === '' || strspn($data, self::ALPHABET) !== strlen($data)) {
            return '';
        }

        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
