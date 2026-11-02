<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Core\Console\Output;
use Core\Database\Seeder;
use Core\Support\Str;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // A random admin password is generated and shown ONCE — there is no default password to guess.
        if (User::where('email', 'admin@example.com')->doesntExist()) {
            $password = Str::random(6) . '-' . Str::random(6) . '-A1!';

            User::factory()->create([
                'name' => 'Administrator',
                'email' => 'admin@example.com',
                'role' => 'admin',
                'password' => $password,
            ]);

            Output::warn("Admin user: admin@example.com / $password   (change it after signing in)");
        }

        User::factory()->count(10)->create();
        Output::line('Created 10 demo users.');
    }
}
