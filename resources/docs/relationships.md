# Relationships

Define relationships as public methods **with a return type**:

```php
use Core\Database\Relations\BelongsTo;
use Core\Database\Relations\BelongsToMany;
use Core\Database\Relations\HasMany;
use Core\Database\Relations\HasOne;

final class User extends Model
{
    public function profile(): HasOne { return $this->hasOne(Profile::class); }      // profiles.user_id
    public function posts(): HasMany { return $this->hasMany(Post::class); }         // posts.user_id
    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); } // pivot role_user
}

final class Post extends Model
{
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
```

Default keys follow conventions (`user_id`, `id`, pivot `role_user` in alphabetical order). Override them with the extra arguments:

```php
$this->hasMany(Post::class, 'author_id', 'id');
$this->belongsTo(User::class, 'author_id', 'id');
$this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
```

## Using relationships

```php
$user->posts;                                   // Collection (loaded once, then cached)
$user->posts()->where('published', 1)->latest()->get();   // keep querying
$post->author->name;
$user->profile?->bio;
```

## Creating related records

```php
$user->posts()->create(['title' => 'Hi']);      // user_id filled automatically
$user->posts()->save($post);

$post->author()->associate($user);  $post->save();
$post->author()->dissociate();      $post->save();
```

## Many-to-many

```php
$user->roles()->attach([1, 2]);
$user->roles()->attach(3, ['assigned_by' => Auth::id()]);   // extra pivot columns
$user->roles()->detach(2);
$user->roles()->detach();                                   // all
$user->roles()->sync([1, 3]);                               // exactly these
```

Pivot migration:

```php
Schema::create('role_user', function (Blueprint $table) {
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->primary(['role_id', 'user_id']);
});
```

## Eager loading (avoid N+1 queries)

Without eager loading, listing 50 posts with their authors runs 51 queries. With it, 2:

```php
$posts = Post::with('author')->latest()->paginate(20);
$users = User::with('posts', 'profile')->get();
$users = User::with(['posts' => fn ($q) => $q->where('published', 1)])->get();

$user->load('posts');                // on a model you already have
$user->relationLoaded('posts');
```

Always eager load:

```php
protected static array $with = ['author'];
```
