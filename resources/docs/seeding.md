# Seeders & factories

## Seeders

Seeders fill the database with starting or demo data.

```bash
php console make:seeder ProductSeeder
php console db:seed                       # runs database/seeders/DatabaseSeeder.php
php console db:seed --class=ProductSeeder
php console migrate:fresh --seed
```

```php
namespace Database\Seeders;

use App\Models\Product;
use Core\Database\Seeder;

final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create(['name' => 'Starter plan', 'price_minor' => 5000]);
        Product::factory()->count(20)->create();
    }
}
```

Call other seeders from `DatabaseSeeder`:

```php
public function run(): void
{
    $this->call([ProductSeeder::class, OrderSeeder::class]);
}
```

The default `DatabaseSeeder` creates `admin@example.com` with a **random password that is printed once** (there is no default password anyone could guess) and 10 demo users.

## Factories

Factories create realistic fake records for seeding and tests.

```bash
php console make:factory ProductFactory
```

```php
namespace Database\Factories;

use App\Models\Product;
use Core\Database\Factory;

final class ProductFactory extends Factory
{
    protected string $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->word()) . ' ' . fake()->randomElement(['Plan', 'Pack', 'Box']),
            'price_minor' => fake()->numberBetween(500, 50000),
            'description' => fake()->sentence(),
        ];
    }
}
```

The model needs `use HasFactory;`.

```php
Product::factory()->create();                         // one, saved
Product::factory()->count(10)->create();              // ten
Product::factory()->make();                           // not saved
Product::factory()->create(['name' => 'Gold']);       // override fields
Product::factory()->state(['featured' => true])->count(3)->create();
```

## Fake data

`fake()` generates Ghana-friendly sample data:

| Method | Example |
|---|---|
| `name()`, `firstName()`, `lastName()` | Akosua Mensah |
| `email()`, `safeEmail()` | kwame.boateng123@example.com |
| `phoneNumber()` | +233 24 555 1234 |
| `city()`, `company()` | Kumasi, Owusu Ventures |
| `word()`, `sentence()`, `paragraph()` | lorem ipsum... |
| `numberBetween(1, 100)`, `boolean()`, `randomElement([...])` | 42, true |
| `dateTimeBetween('-1 year', 'now')`, `uuid()` | DateTimeImmutable, UUID |

`fake()->unique()->safeEmail()` never repeats a value.
