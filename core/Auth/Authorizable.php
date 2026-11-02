<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Add to the User model:  $user->can('update', $post);  $user->cannot('delete', $post);
 */
trait Authorizable
{
    public function can(string $ability, mixed ...$arguments): bool
    {
        return Gate::forUser($this, $ability, ...$arguments);
    }

    public function cannot(string $ability, mixed ...$arguments): bool
    {
        return !$this->can($ability, ...$arguments);
    }
}
