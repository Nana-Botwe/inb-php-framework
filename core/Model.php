<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Core\Database\Relations\BelongsTo;
use Core\Database\Relations\BelongsToMany;
use Core\Database\Relations\HasMany;
use Core\Database\Relations\HasOne;
use Core\Database\Relations\Relation;
use Core\Support\Collection;
use Core\Support\Str;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;

/**
 * Active-record model (like Eloquent).
 *
 *   $user = User::create(['name' => 'Ama', 'email' => 'ama@example.com']);
 *   $user = User::find(1);  User::where('active', 1)->latest()->get();  User::with('posts')->paginate();
 *   $user->posts;  $user->posts()->create([...]);  $post->author;
 *
 * Static calls that aren't defined here (where, orderBy, with, latest, scopes ...) are forwarded
 * to a new query, exactly like Laravel.
 *
 * @method static QueryBuilder where(string|array|Closure $column, mixed $operator = null, mixed $value = null)
 * @method static QueryBuilder whereIn(string $column, array $values)
 * @method static QueryBuilder with(string|array ...$relations)
 * @method static QueryBuilder orderBy(string $column, string $direction = 'ASC')
 * @method static QueryBuilder latest(string $column = 'created_at')
 * @method static QueryBuilder withTrashed()
 * @method static QueryBuilder onlyTrashed()
 * @method static \Core\Support\Paginator paginate(int $perPage = 15)
 * @method static int count()
 */
abstract class Model implements JsonSerializable
{
    /** Table name. Defaults to the snake_case plural of the class name (BlogPost -> blog_posts). */
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** Columns that may be set through create()/fill()/update(). Everything else is ignored. */
    protected static array $fillable = [];

    /** Columns removed from toArray()/JSON output (e.g. password). */
    protected static array $hidden = [];

    /**
     * Attribute casting: int, float, bool, string, array, json, datetime, date,
     * encrypted (stored encrypted with APP_KEY), hashed (auto-hashed on set, e.g. passwords).
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /** Relations always eager loaded. */
    protected static array $with = [];

    /** Maintain created_at / updated_at columns automatically. */
    protected static bool $timestamps = true;

    /** Database connection name from config/database.php (null = the default connection). */
    protected static ?string $connection = null;

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    /** @var array<string, mixed> */
    protected array $relations = [];

    public bool $exists = false;

    /** @var array<class-string, array<string, Closure[]>> */
    private static array $listeners = [];

    /** @var array<class-string, bool> */
    private static array $booted = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        if (!isset(self::$booted[static::class])) {
            self::$booted[static::class] = true;
            static::booted();
        }

        $this->fill($attributes);
    }

    /** Override to register model events: static::creating(fn ($model) => ...) */
    protected static function booted(): void
    {
    }

    // ------------------------------------------------------------------ static API

    public static function table(): string
    {
        return static::$table !== ''
            ? static::$table
            : Str::plural(Str::snake((new ReflectionClass(static::class))->getShortName()));
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    /** The connection this model reads and writes. */
    public static function connection(): Database
    {
        return Database::connection(static::$connection);
    }

    public static function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses(static::class) ?: [], true);
    }

    public static function query(): QueryBuilder
    {
        $query = (new QueryBuilder(static::connection(), static::table()))->setModel(static::class);

        return static::$with === [] ? $query : $query->with(static::$with);
    }

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function find(int|string|null $id): ?static
    {
        return $id === null ? null : static::query()->find($id);
    }

    /** Like find(), but responds with 404 if the row doesn't exist. */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new HttpException(404);
    }

    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes Columns to search by.
     * @param array<string, mixed> $values Extra values when creating.
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        return static::query()->where($attributes)->first() ?? static::create($attributes + $values);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::query()->where($attributes)->first();
        if ($model === null) {
            return static::create($attributes + $values);
        }
        $model->update($values);

        return $model;
    }

    /** Delete by primary key(s). Returns how many were deleted. */
    public static function destroy(int|string|array $ids): int
    {
        $count = 0;
        foreach (static::query()->whereIn(static::$primaryKey, (array) $ids)->get() as $model) {
            $count += (int) $model->delete();
        }

        return $count;
    }

    /** @param array<string, mixed> $row */
    public static function newFromRow(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::query()->{$method}(...$arguments);
    }

    // ------------------------------------------------------------------ events

    public static function creating(Closure $callback): void { static::listen('creating', $callback); }
    public static function created(Closure $callback): void { static::listen('created', $callback); }
    public static function updating(Closure $callback): void { static::listen('updating', $callback); }
    public static function updated(Closure $callback): void { static::listen('updated', $callback); }
    public static function saving(Closure $callback): void { static::listen('saving', $callback); }
    public static function saved(Closure $callback): void { static::listen('saved', $callback); }
    public static function deleting(Closure $callback): void { static::listen('deleting', $callback); }
    public static function deleted(Closure $callback): void { static::listen('deleted', $callback); }

    private static function listen(string $event, Closure $callback): void
    {
        self::$listeners[static::class][$event][] = $callback;
    }

    /** Returns false if a listener cancelled the action. */
    protected function fireEvent(string $event): bool
    {
        foreach (self::$listeners[static::class][$event] ?? [] as $listener) {
            if ($listener($this) === false) {
                return false;
            }
        }
        Event::dispatch('model.' . $event . ': ' . static::class, [$this]);

        return true;
    }

    // ------------------------------------------------------------------ persistence

    /**
     * Mass-assign only $fillable columns (protects against users posting extra fields like "is_admin").
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, static::$fillable, true)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /** Set attributes ignoring $fillable (only use with trusted data). @param array<string, mixed> $attributes */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function save(): bool
    {
        if (!$this->fireEvent('saving')) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($this->exists) {
            if (!$this->fireEvent('updating')) {
                return false;
            }
            $dirty = $this->getDirty();
            if ($dirty !== []) {
                if (static::$timestamps) {
                    $this->attributes['updated_at'] = $dirty['updated_at'] = $now;
                }
                $this->newQueryForThis()->update($dirty);
            }
            $this->fireEvent('updated');
        } else {
            if (!$this->fireEvent('creating')) {
                return false;
            }
            if (static::$timestamps) {
                $this->attributes['created_at'] ??= $now;
                $this->attributes['updated_at'] ??= $now;
            }
            $id = static::query()->insertGetId($this->attributes, static::$primaryKey);
            if (!isset($this->attributes[static::$primaryKey]) && $id !== '' && $id !== '0') {
                $this->attributes[static::$primaryKey] = ctype_digit($id) ? (int) $id : $id;
            }
            $this->exists = true;
            $this->fireEvent('created');
        }

        $this->original = $this->attributes;
        $this->fireEvent('saved');

        return true;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes = []): bool
    {
        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists || !$this->fireEvent('deleting')) {
            return false;
        }

        if (static::usesSoftDeletes()) {
            $this->attributes['deleted_at'] = date('Y-m-d H:i:s');
            $this->newQueryForThis()->withTrashed()->update(['deleted_at' => $this->attributes['deleted_at']]);
            $this->original['deleted_at'] = $this->attributes['deleted_at'];
        } else {
            $this->newQueryForThis()->delete();
            $this->exists = false;
        }

        $this->fireEvent('deleted');

        return true;
    }

    /** Re-read this row from the database. */
    public function refresh(): static
    {
        $fresh = static::query()->withTrashed()->find($this->getKey());
        if ($fresh instanceof self) {
            $this->attributes = $this->original = $fresh->attributes;
            $this->relations = [];
        }

        return $this;
    }

    public function fresh(): ?static
    {
        return static::query()->withTrashed()->find($this->getKey());
    }

    protected function newQueryForThis(): QueryBuilder
    {
        return (new QueryBuilder(static::connection(), static::table()))
            ->setModel(static::class)
            ->where(static::$primaryKey, $this->getKey());
    }

    public function getKey(): int|string|null
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /** @return array<string, mixed> */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] != $value) {
                $dirty[$key] = $value;
            }
        }

        unset($dirty[static::$primaryKey]);

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        return $key === null ? $this->getDirty() !== [] : array_key_exists($key, $this->getDirty());
    }

    // ------------------------------------------------------------------ attributes & casts

    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castGet($key, $this->attributes[$key]);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if ($this->isRelationMethod($key)) {
            return $this->relations[$key] = $this->{$key}()->getResults();
        }

        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->castSet($key, $value);
    }

    /** @return array<string, mixed> Raw database values. */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    private function castGet(string $key, mixed $value): mixed
    {
        $cast = static::$casts[$key] ?? null;
        if ($cast === null || $value === null) {
            return $value;
        }

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'decimal' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            'datetime', 'date', 'immutable_datetime' => $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value),
            'encrypted' => Crypt::tryDecrypt((string) $value),
            default => $value,
        };
    }

    private function castSet(string $key, mixed $value): mixed
    {
        $cast = static::$casts[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'bool', 'boolean' => (int) (bool) $value,
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            'datetime', 'immutable_datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value,
            'encrypted' => Crypt::encrypt($value),
            'hashed' => is_string($value) && password_get_info($value)['algo'] !== null ? $value : Hash::make((string) $value),
            default => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
        };
    }

    // ------------------------------------------------------------------ relationships

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey ?? $this->defaultForeignKey(), $localKey ?? static::$primaryKey);
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey ?? $this->defaultForeignKey(), $localKey ?? static::$primaryKey);
    }

    /** @param class-string<Model> $related */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey ??= Str::snake((new ReflectionClass($related))->getShortName()) . '_' . $related::primaryKey();

        return new BelongsTo($this, $related, $foreignKey, $ownerKey ?? $related::primaryKey());
    }

    /** @param class-string<Model> $related */
    protected function belongsToMany(string $related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): BelongsToMany
    {
        $relatedName = Str::snake((new ReflectionClass($related))->getShortName());
        $thisName = Str::snake((new ReflectionClass(static::class))->getShortName());

        if ($table === null) {
            $names = [$thisName, $relatedName];
            sort($names);
            $table = implode('_', $names);
        }

        return new BelongsToMany($this, $related, $table, $foreignPivotKey ?? $thisName . '_id', $relatedPivotKey ?? $relatedName . '_id');
    }

    private function defaultForeignKey(): string
    {
        return Str::snake((new ReflectionClass(static::class))->getShortName()) . '_' . static::$primaryKey;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /** Lazy eager-load relations on an existing model: $user->load('posts') */
    public function load(string ...$relations): static
    {
        static::eagerLoadRelations([$this], array_fill_keys($relations, null));

        return $this;
    }

    /**
     * @param array<int, Model> $models
     * @param array<string, Closure|null> $relations
     */
    public static function eagerLoadRelations(array $models, array $relations): void
    {
        if ($models === []) {
            return;
        }

        foreach ($relations as $name => $constraint) {
            if (!$models[0]->isRelationMethod($name)) {
                throw new \InvalidArgumentException("Relation [$name] is not defined on " . static::class . '.');
            }
            /** @var Relation $relation */
            $relation = $models[0]->{$name}();
            $relation->eagerLoad($models, $name, $constraint);
        }
    }

    /** True for public methods declared on the subclass that return a Relation. */
    private function isRelationMethod(string $name): bool
    {
        if (!method_exists($this, $name)) {
            return false;
        }

        $method = new ReflectionMethod($this, $name);
        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0
            || $method->getDeclaringClass()->getName() === self::class) {
            return false;
        }

        $type = $method->getReturnType();

        return $type instanceof \ReflectionNamedType && is_a($type->getName(), Relation::class, true);
    }

    // ------------------------------------------------------------------ serialisation

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [];
        foreach (array_diff_key($this->attributes, array_flip(static::$hidden)) as $key => $value) {
            $value = $this->castGet($key, $value);
            $data[$key] = $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        foreach ($this->relations as $name => $relation) {
            if (in_array($name, static::$hidden, true)) {
                continue;
            }
            $data[$name] = $relation instanceof Collection || $relation instanceof self ? $relation->toArray() : $relation;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }
}
