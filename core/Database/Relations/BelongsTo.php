<?php

declare(strict_types=1);

namespace Core\Database\Relations;

use Closure;
use Core\Model;

/**
 * Inverse of one-to-many:  public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
 */
class BelongsTo extends Relation
{
    /** @param class-string<Model> $related */
    public function __construct(Model $child, string $related, protected string $foreignKey, protected string $ownerKey)
    {
        parent::__construct($child, $related);
        $this->query->where($this->ownerKey, $child->getAttributes()[$foreignKey] ?? null);
    }

    public function getResults(): mixed
    {
        return ($this->parent->getAttributes()[$this->foreignKey] ?? null) === null ? null : $this->query->first();
    }

    public function eagerLoad(array $models, string $name, ?Closure $constraint): void
    {
        $query = $this->related::query()->whereIn($this->ownerKey, $this->keys($models, $this->foreignKey));
        if ($constraint !== null) {
            $constraint($query);
        }

        $owners = [];
        foreach ($query->get() as $owner) {
            $owners[(string) $owner->getAttributes()[$this->ownerKey]] = $owner;
        }

        foreach ($models as $model) {
            $model->setRelation($name, $owners[(string) ($model->getAttributes()[$this->foreignKey] ?? '')] ?? null);
        }
    }

    /** Set the foreign key from a model: $post->author()->associate($user); $post->save(); */
    public function associate(?Model $model): Model
    {
        $this->parent->{$this->foreignKey} = $model?->getAttributes()[$this->ownerKey] ?? null;

        return $this->parent;
    }

    public function dissociate(): Model
    {
        return $this->associate(null);
    }
}
