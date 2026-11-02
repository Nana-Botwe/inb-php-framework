<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Core\Testing\TestCase as BaseTestCase;

/**
 * Base class for your tests. Run them with: php console test
 */
abstract class TestCase extends BaseTestCase
{
    /** @param array<string, mixed> $attributes */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
