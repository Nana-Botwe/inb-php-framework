<?php

declare(strict_types=1);

namespace Core\Auth;

final class NewAccessToken
{
    public function __construct(public readonly PersonalAccessToken $accessToken, public readonly string $plainTextToken)
    {
    }
}
