<?php

declare(strict_types=1);

namespace Core;

/**
 * Password hashing (bcrypt by default, or argon2id if configured in config/hashing.php).
 */
final class Hash
{
    public static function make(string $value): string
    {
        return password_hash($value, self::algorithm(), self::options());
    }

    public static function check(string $value, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($value, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    private static function algorithm(): string
    {
        return Config::get('hashing.driver', 'bcrypt') === 'argon2id' && defined('PASSWORD_ARGON2ID')
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private static function options(): array
    {
        return self::algorithm() === PASSWORD_BCRYPT
            ? ['cost' => (int) Config::get('hashing.bcrypt_rounds', 12)]
            : [];
    }
}
