<?php

declare(strict_types=1);

namespace Core\Database\Relations;

use Closure;
use Core\Model;
use Core\QueryBuilder;

/**
 * Base relationship. Unknown method calls go to the underlying query, so you can write
 * $user->posts()->where('published', 1)->latest()->get().
 *
 * @mixin QueryBuilder
 */
abstract class Relation
{
    protected QueryBuilder $query;

    /** @param class-string<Model> $related */
    public function __construct(protected Model $parent, protected string $related)
    {
        $this->query = $related::query();
    }

    /** Results for a single parent (lazy loading). */
    abstract public function getResults(): mixed;

    /**
     * Load the relation for many parents with ONE query and attach the results.
     *
     * @param array<int, Model> $models
     */
    abstract public function eagerLoad(array $models, string $name, ?Closure $constraint): void;

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * @param array<int, Model> $models
     * @return array<int, int|string>
     */
    protected function keys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->getAttributes()[$key] ?? null;
            if ($value !== null) {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->query->{$method}(...$arguments);

        return $result === $this->query ? $this : $result;
    }
}
