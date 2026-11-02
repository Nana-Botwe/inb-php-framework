<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Core\Database\Factory;

final class UserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => date('Y-m-d H:i:s'),
            'password' => 'Password123!',   // hashed automatically by the User model's "hashed" cast
            'role' => 'user',
        ];
    }
}
