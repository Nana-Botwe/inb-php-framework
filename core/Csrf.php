<?php

declare(strict_types=1);

namespace Core;

/**
 * CSRF protection. One secret per session (256-bit, from random_bytes), but every token printed
 * into a page is MASKED with a fresh random one-time pad, so the value differs on every render.
 * That defeats compression side-channel attacks (BREACH) that try to read the token byte by byte.
 *
 * Forms include it with @csrf (Blade) or <?= csrf_field() ?>.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    /** The raw session secret (never printed directly). */
    public static function secret(): string
    {
        $secret = Session::get(self::KEY);

        if (!is_string($secret) || strlen($secret) !== 64) {
            $secret = bin2hex(random_bytes(32));
            Session::set(self::KEY, $secret);
        }

        return $secret;
    }

    /** A freshly masked token for output: base64url(pad || (pad XOR secret)). */
    public static function token(): string
    {
        $secret = self::secret();
        $pad = random_bytes(strlen($secret));

        return rtrim(strtr(base64_encode($pad . ($pad ^ $secret)), '+/', '-_'), '=');
    }

    public static function verify(mixed $token): bool
    {
        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            return false;
        }

        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $secret = self::secret();
        $length = strlen($secret);

        if ($raw === false || strlen($raw) !== $length * 2) {
            return false;
        }

        $unmasked = substr($raw, 0, $length) ^ substr($raw, $length);

        return hash_equals($secret, $unmasked);
    }

    /** New secret (on login/logout) so tokens from before can't be replayed. */
    public static function refresh(): void
    {
        Session::forget(self::KEY);
    }
}
