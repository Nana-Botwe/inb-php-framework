# Migrations

Migrations are version control for your database: each file creates or changes tables, and can be undone.

```bash
php console make:migration create_posts_table           # new table
php console make:migration add_slug_to_posts_table      # change a table
php console migrate                                     # run new migrations
php console migrate:status
php console migrate:rollback                            # undo the last batch
php console migrate:rollback --step=3
php console migrate:reset                               # undo everything
php console migrate:fresh --seed                        # drop all tables, migrate, seed
```

> **Warning:** `migrate:fresh` and `migrate:reset` delete data. In production they refuse to run unless you add `--force`.

## A migration

```php
use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('slug')->unique();
            $table->text('body')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('status', ['draft', 'review', 'live'])->default('draft');
            $table->json('meta')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

The same migration runs on **MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle and Firebird** — types, auto-increment/identity columns, defaults and foreign-key rules are translated for each database. Run migrations on another connection with `Schema::connection('name')`.

## Column types

| Method | Column |
|---|---|
| `id()`, `bigIncrements('id')`, `increments('id')` | auto-increment primary key |
| `foreignId('user_id')` | unsigned big integer for relations |
| `string('name', 255)`, `char('code', 3)` | VARCHAR / CHAR |
| `text()`, `mediumText()`, `longText()` | long text |
| `integer()`, `tinyInteger()`, `smallInteger()`, `bigInteger()` | integers |
| `unsignedInteger()`, `unsignedBigInteger()` | unsigned integers |
| `decimal('price', 10, 2)`, `float()`, `double()` | numbers (use decimal or minor units for money) |
| `boolean()` | TINYINT(1) |
| `date()`, `dateTime()`, `timestamp()`, `time()`, `year()` | dates |
| `json()` | JSON |
| `enum('status', [...])` | ENUM (CHECK constraint on SQLite) |
| `uuid()`, `ipAddress()`, `rememberToken()` | helpers |
| `timestamps()`, `softDeletes()` | created_at/updated_at, deleted_at |

## Modifiers

```php
->nullable()  ->default('x')  ->unsigned()  ->unique()  ->index()  ->primary()
->useCurrent()  ->useCurrentOnUpdate()  ->comment('...')  ->after('email')   // MySQL only: comment/after/on-update
```

## Indexes and foreign keys

```php
$table->index(['user_id', 'created_at']);
$table->unique(['email', 'tenant_id']);
$table->primary(['role_id', 'user_id']);

$table->foreignId('user_id')->constrained();                         // references users.id
$table->foreignId('author_id')->constrained('users')->nullOnDelete();
$table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
```

## Changing tables

```php
Schema::table('posts', function (Blueprint $table) {
    $table->string('subtitle')->nullable()->after('title');
    $table->dropColumn('legacy_flag');
    $table->dropIndex('posts_status_index');
    $table->dropForeign('posts_user_id_foreign');   // MySQL
});

Schema::rename('posts', 'articles');
Schema::hasTable('posts');
Schema::hasColumn('posts', 'slug');
```

> **Note:** SQLite can't add or drop foreign keys on an existing table — define them in `Schema::create()`.

Need something exotic? Use `DB::statement('ALTER TABLE ...')` inside `up()`.
