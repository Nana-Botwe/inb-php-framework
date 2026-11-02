<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Model;

/**
 * API token (like Laravel Sanctum). Only a SHA-256 hash of the token is stored, so a database
 * leak does not reveal usable tokens. The plain token is shown once, when created.
 */
final class PersonalAccessToken extends Model
{
    protected static string $table = 'personal_access_tokens';

    protected static array $fillable = ['name', 'abilities', 'expires_at'];

    protected static array $hidden = ['token'];

    protected static array $casts = ['abilities' => 'array'];

    /** Find a token from "id|plaintext". */
    public static function findToken(string $plain): ?self
    {
        if (!str_contains($plain, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plain, 2);
        if (!ctype_digit($id) || $secret === '') {
            return null;
        }

        $token = self::find((int) $id);
        if ($token === null || !\Core\Token::matches((string) $token->getAttributes()['token'], $secret)) {
            return null;
        }

        $expires = $token->getAttributes()['expires_at'] ?? null;
        if ($expires !== null && strtotime((string) $expires) < time()) {
            return null;
        }

        return $token;
    }

    public function tokenable(): ?Model
    {
        $class = (string) $this->getAttributes()['tokenable_type'];

        return is_a($class, Model::class, true) ? $class::find($this->getAttributes()['tokenable_id']) : null;
    }

    /** Does this token allow an ability? ('*' allows everything) */
    public function can(string $ability): bool
    {
        $abilities = (array) $this->abilities;

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
