<?php

declare(strict_types=1);

namespace Core;

/**
 * Secure token toolkit — use it for anything that acts like a password (invite links,
 * one-time codes, payment references, unsubscribe links, API keys).
 *
 *   $plain = Token::random();               // 256-bit random, URL-safe
 *   $store = Token::hash($plain);           // keep ONLY this in the database
 *   Token::equals($store, Token::hash($input));   // constant-time comparison
 *
 *   $t = Token::sign(['user' => 5, 'purpose' => 'invite'], 60);  // tamper-proof, expires in 60 min
 *   Token::verify($t, 'invite')  -> ['user' => 5, ...] or null
 *
 *   Token::otp(6)  -> "493027"   (numeric one-time code; store Token::hash() of it, limit attempts)
 */
final class Token
{
    /** URL-safe random token. 32 bytes = 256 bits of entropy (unguessable). */
    public static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(16, $bytes))), '+/', '-_'), '=');
    }

    /** Keyed hash (HMAC-SHA256 with APP_KEY): a stolen database can't be used to forge or reverse tokens. */
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, Crypt::key());
    }

    /**
     * Hashes of a token under the current AND previous APP_KEYs. Use for database lookups
     * (whereIn('token', Token::hashCandidates($plain))) so tokens survive a key rotation.
     *
     * @return string[]
     */
    public static function hashCandidates(string $token): array
    {
        return Crypt::hmacCandidates($token);
    }

    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /** Does $plain match the stored hash under any current/previous key? Constant time. */
    public static function matches(string $storedHash, string $plain): bool
    {
        return Crypt::verifyHmac($plain, $storedHash);
    }

    /** Numeric code with uniform distribution (no modulo bias). */
    public static function otp(int $digits = 6): string
    {
        $digits = max(4, min(10, $digits));
        $code = '';
        for ($i = 0; $i < $digits; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    /** Unique, unguessable reference such as "INB_20261019_7Hq2...". */
    public static function reference(string $prefix = 'REF'): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'REF') . '_' . date('Ymd') . '_' . bin2hex(random_bytes(10));
    }

    /**
     * Signed + timestamped token carrying data. The data is readable (base64), NOT secret —
     * use Crypt::encrypt() when the content must stay hidden.
     *
     * @param array<string, mixed> $data
     */
    public static function sign(array $data, int $minutes = 60, string $purpose = ''): string
    {
        $payload = ['d' => $data, 'e' => time() + $minutes * 60, 'p' => $purpose !== '' ? $purpose : ($data['purpose'] ?? ''), 'n' => bin2hex(random_bytes(8))];
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return $body . '.' . hash_hmac('sha256', $body, Crypt::key());
    }

    /** @return array<string, mixed>|null The data, or null if forged, expired or for another purpose. */
    public static function verify(string $token, string $purpose = ''): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !Crypt::verifyHmac($parts[0], $parts[1])) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($payload) || (int) ($payload['e'] ?? 0) < time()) {
            return null;
        }

        if ($purpose !== '' && !hash_equals((string) ($payload['p'] ?? ''), $purpose)) {
            return null;
        }

        return is_array($payload['d'] ?? null) ? $payload['d'] : null;
    }
}
