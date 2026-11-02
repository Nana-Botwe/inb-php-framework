<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Authenticated encryption (AES-256-GCM) with the APP_KEY from .env.
 * Generate a key with:  php console key:generate
 *
 *   $token = Crypt::encrypt(['id' => 5]);   Crypt::decrypt($token);   // tampered data throws
 */
final class Crypt
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(mixed $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(serialize($value), self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the data.');
        }

        return self::base64UrlEncode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): mixed
    {
        $raw = self::base64UrlDecode($payload);
        if ($raw === null || strlen($raw) < 29) {
            throw new RuntimeException('The payload is invalid.');
        }

        // Try the current key first, then previous keys (key rotation: APP_PREVIOUS_KEYS).
        foreach (self::keys() as $key) {
            $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            if ($plain !== false) {
                return unserialize($plain, ['allowed_classes' => false]);
            }
        }

        throw new RuntimeException('The payload is invalid.');
    }

    /** Returns null instead of throwing (useful for cookies). */
    public static function tryDecrypt(?string $payload): mixed
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            return self::decrypt($payload);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** Keyed signature for URLs/tokens (always made with the current key). */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    /** Signatures with the current AND previous keys — use when VERIFYING. @return string[] */
    public static function hmacCandidates(string $data): array
    {
        return array_map(static fn (string $key) => hash_hmac('sha256', $data, $key), self::keys());
    }

    /** Constant-time check of a signature against the current and previous keys. */
    public static function verifyHmac(string $data, string $signature): bool
    {
        $valid = false;
        foreach (self::hmacCandidates($data) as $candidate) {
            $valid = hash_equals($candidate, $signature) || $valid;
        }

        return $valid;
    }

    /** @return string[] Current key first, then APP_PREVIOUS_KEYS (newest first). */
    public static function keys(): array
    {
        $keys = [self::key()];
        foreach ((array) Config::get('app.previous_keys', []) as $previous) {
            $decoded = self::decodeKey((string) $previous);
            if ($decoded !== null && !in_array($decoded, $keys, true)) {
                $keys[] = $decoded;
            }
        }

        return $keys;
    }

    private static function decodeKey(string $key): ?string
    {
        $key = trim($key);
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return strlen($key) === 32 ? $key : null;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function key(): string
    {
        $key = self::decodeKey((string) Config::get('app.key', ''));

        if ($key === null) {
            throw new RuntimeException('APP_KEY is missing or invalid. Run: php console key:generate');
        }

        return $key;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
