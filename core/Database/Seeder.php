<?php

declare(strict_types=1);

namespace Core\Database;

use Core\App;

/**
 * Seeders fill the database with starting/demo data:  php console db:seed
 *
 *   class DatabaseSeeder extends Seeder {
 *       public function run(): void { $this->call([UserSeeder::class]); }
 *   }
 */
abstract class Seeder
{
    /** @var callable(string): void|null */
    public $output = null;

    abstract public function run(): void;

    /** @param class-string<Seeder>|array<int, class-string<Seeder>> $seeders */
    public function call(string|array $seeders): void
    {
        foreach ((array) $seeders as $class) {
            if ($this->output !== null) {
                ($this->output)("Seeding: $class");
            }
            /** @var Seeder $seeder */
            $seeder = App::instance()->make($class);
            $seeder->output = $this->output;
            $seeder->run();
        }
    }
}
