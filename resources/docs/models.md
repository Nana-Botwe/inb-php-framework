# Models (ORM)

Models represent database tables, like Laravel's Eloquent.

```bash
php console make:model Post -m -f -s      # model + migration + factory + seeder
```

```php
namespace App\Models;

use Core\Database\HasFactory;
use Core\Model;
use Core\SoftDeletes;

final class Post extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static string $table = 'posts';          // default: plural snake_case of the class
    protected static array $fillable = ['title', 'body', 'published'];
    protected static array $hidden = ['internal_notes'];
    protected static array $casts = ['published' => 'bool', 'meta' => 'array', 'published_at' => 'datetime'];
}
```

## Reading

```php
Post::all();
Post::find(1);
Post::findOrFail(1);                       // 404 if missing
Post::where('published', 1)->latest()->get();
Post::where('title', 'LIKE', '%php%')->first();
Post::whereIn('id', [1, 2])->get();
Post::count();
Post::paginate(10);
```

Every [query builder](database) method works on models (`Post::where(...)`, `->orderBy()`, `->paginate()`...).

```php
$post->title;           // attributes (with casts applied)
$post->toArray();       // without $hidden fields
json_encode($post);     // same
```

## Creating, updating, deleting

```php
$post = Post::create(['title' => 'Hello', 'body' => '...']);

$post->title = 'Hello world';
$post->save();

$post->update(['title' => 'New title']);

$post->delete();
Post::destroy([1, 2, 3]);

Post::firstOrCreate(['slug' => 'hello'], ['title' => 'Hello']);
Post::updateOrCreate(['slug' => 'hello'], ['title' => 'Updated']);
```

`created_at` and `updated_at` are filled automatically (disable with `protected static bool $timestamps = false;`). Only changed columns are saved.

## Mass assignment protection

`create()`, `fill()` and `update()` only accept columns in `$fillable`. Anything else (like `role`, `is_admin`, `balance`) is silently ignored. Set such columns deliberately:

```php
$user->forceFill(['role' => 'admin'])->save();
```

## Casts

| Cast | Stored as | You get |
|---|---|---|
| `int`, `float`, `bool`, `string` | number/text | that PHP type |
| `array` / `json` | JSON text | PHP array |
| `datetime`, `date` | `Y-m-d H:i:s` | `DateTimeImmutable` |
| `encrypted` | AES-256-GCM ciphertext | the original value |
| `hashed` | bcrypt hash | the hash (assign plain text, it's hashed for you) |

```php
protected static array $casts = ['ghana_card' => 'encrypted', 'password' => 'hashed'];
```

## Soft deletes

Add `use SoftDeletes;` and a `$table->softDeletes()` column:

```php
$post->delete();                  // sets deleted_at; hidden from normal queries
Post::withTrashed()->get();
Post::onlyTrashed()->get();
$post->restore();
$post->forceDelete();             // really deletes
$post->trashed();
```

## Local scopes

```php
public function scopePublished(QueryBuilder $query): QueryBuilder
{
    return $query->where('published', 1);
}

Post::published()->latest()->get();
```

## Model events

```php
protected static function booted(): void
{
    static::creating(function (Post $post) {
        $post->slug = Core\Support\Str::slug($post->title);
    });
    static::deleting(fn (Post $post) => $post->comments()->delete());   // return false to cancel
}
```

Events: `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`.

## Collections

Queries return `Core\Support\Collection`:

```php
$posts = Post::all();
$posts->count();
$posts->pluck('title');
$posts->where('published', true);
$posts->sortByDesc('created_at')->take(5);
$posts->groupBy('user_id');
$posts->map(fn ($p) => strtoupper($p->title));
$posts->sum('views');
$posts->first(); $posts->isEmpty();
collect([1, 2, 3])->filter(fn ($n) => $n > 1)->values()->all();   // [2, 3]
```

Also: `filter`, `reject`, `each`, `reduce`, `keyBy`, `sortBy`, `unique`, `chunk`, `merge`, `contains`, `avg`, `min`, `max`, `implode`, `toArray`, `toJson`.

See [Relationships](relationships) for `hasMany`, `belongsTo` and more.
