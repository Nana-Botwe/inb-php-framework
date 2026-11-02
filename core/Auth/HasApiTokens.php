<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Auth;
use Core\Support\Collection;
use Core\Support\Str;

/**
 * Add to the User model:  use HasApiTokens;
 *
 *   $plain = $user->createToken('mobile-app', ['orders:read'])->plainTextToken;   // show this once
 *   Clients send:  Authorization: Bearer <plainTextToken>
 *   Routes:        ->middleware('auth:api')
 */
trait HasApiTokens
{
    /** @param string[] $abilities */
    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $secret = Str::random(48);

        $token = new PersonalAccessToken(['name' => $name, 'abilities' => $abilities, 'expires_at' => $expiresAt?->format('Y-m-d H:i:s')]);
        $token->forceFill([
            'tokenable_type' => static::class,
            'tokenable_id' => $this->getKey(),
            'token' => \Core\Token::hash($secret),
        ])->save();

        return new NewAccessToken($token, $token->getKey() . '|' . $secret);
    }

    public function tokens(): Collection
    {
        return PersonalAccessToken::where('tokenable_type', static::class)->where('tokenable_id', $this->getKey())->get();
    }

    /** Revoke every token of this user. */
    public function revokeTokens(): int
    {
        return PersonalAccessToken::where('tokenable_type', static::class)->where('tokenable_id', $this->getKey())->delete();
    }

    /** The token used for the current API request, if any. */
    public function currentAccessToken(): ?PersonalAccessToken
    {
        return Auth::token();
    }

    public function tokenCan(string $ability): bool
    {
        return Auth::token()?->can($ability) ?? false;
    }
}
