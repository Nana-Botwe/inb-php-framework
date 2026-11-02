<?php

declare(strict_types=1);

namespace Core\Database\Relations;

use Closure;
use Core\Model;
use Core\Support\Collection;

/**
 * One-to-many:  public function posts(): HasMany { return $this->hasMany(Post::class); }
 */
class HasMany extends Relation
{
    /** @param class-string<Model> $related */
    public function __construct(Model $parent, string $related, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($parent, $related);
        $this->query->where($this->foreignKey, $parent->getAttributes()[$localKey] ?? null);
    }

    public function getResults(): mixed
    {
        return $this->query->get();
    }

    public function eagerLoad(array $models, string $name, ?Closure $constraint): void
    {
        $query = $this->related::query()->whereIn($this->foreignKey, $this->keys($models, $this->localKey));
        if ($constraint !== null) {
            $constraint($query);
        }

        $grouped = [];
        foreach ($query->get() as $result) {
            $grouped[(string) $result->getAttributes()[$this->foreignKey]][] = $result;
        }

        foreach ($models as $model) {
            $key = (string) ($model->getAttributes()[$this->localKey] ?? '');
            $model->setRelation($name, $this->wrap($grouped[$key] ?? []));
        }
    }

    /** @param array<int, Model> $results */
    protected function wrap(array $results): mixed
    {
        return new Collection($results);
    }

    /**
     * Create a related model with the foreign key filled in: $user->posts()->create([...])
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Model
    {
        $model = new $this->related($attributes);
        $model->{$this->foreignKey} = $this->parent->getAttributes()[$this->localKey] ?? null;
        $model->save();

        return $model;
    }

    public function save(Model $model): Model
    {
        $model->{$this->foreignKey} = $this->parent->getAttributes()[$this->localKey] ?? null;
        $model->save();

        return $model;
    }
}
