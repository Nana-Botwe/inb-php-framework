<?php

declare(strict_types=1);

namespace Core\Database\Relations;

use Closure;
use Core\Database;
use Core\Model;
use Core\QueryBuilder;
use Core\Support\Collection;

/**
 * Many-to-many through a pivot table:
 *   public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); }   // pivot "role_user"
 *   $user->roles()->attach([1, 2]);  ->detach(2);  ->sync([1, 3]);
 */
class BelongsToMany extends Relation
{
    private const PIVOT_KEY = 'pivot_parent_key';

    /** @param class-string<Model> $related */
    public function __construct(
        Model $parent,
        string $related,
        protected string $pivotTable,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
    ) {
        parent::__construct($parent, $related);
        $this->joinPivot($this->query)->where($this->pivotTable . '.' . $this->foreignPivotKey, $parent->getKey());
    }

    private function joinPivot(QueryBuilder $query): QueryBuilder
    {
        $relatedTable = $this->related::table();

        return $query
            ->select($relatedTable . '.*', $this->pivotTable . '.' . $this->foreignPivotKey . ' as ' . self::PIVOT_KEY)
            ->join($this->pivotTable, $this->pivotTable . '.' . $this->relatedPivotKey, '=', $relatedTable . '.' . $this->related::primaryKey());
    }

    public function getResults(): mixed
    {
        return $this->query->get();
    }

    public function eagerLoad(array $models, string $name, ?Closure $constraint): void
    {
        $query = $this->joinPivot($this->related::query())
            ->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, $this->keys($models, $models[0]::primaryKey()));
        if ($constraint !== null) {
            $constraint($query);
        }

        $grouped = [];
        foreach ($query->get() as $result) {
            $grouped[(string) $result->getAttributes()[self::PIVOT_KEY]][] = $result;
        }

        foreach ($models as $model) {
            $model->setRelation($name, new Collection($grouped[(string) $model->getKey()] ?? []));
        }
    }

    /**
     * @param int|string|array<int, int|string> $ids
     * @param array<string, mixed> $extra Extra pivot columns.
     */
    public function attach(int|string|array $ids, array $extra = []): void
    {
        $rows = [];
        foreach ((array) $ids as $id) {
            $rows[] = [$this->foreignPivotKey => $this->parent->getKey(), $this->relatedPivotKey => $id] + $extra;
        }
        if ($rows !== []) {
            $this->pivot()->insert($rows);
        }
    }

    /** @param int|string|array<int, int|string>|null $ids null = detach all */
    public function detach(int|string|array|null $ids = null): int
    {
        $query = $this->pivot()->where($this->foreignPivotKey, $this->parent->getKey());
        if ($ids !== null) {
            $query->whereIn($this->relatedPivotKey, (array) $ids);
        }

        return $query->delete();
    }

    /**
     * Make the pivot contain exactly these IDs.
     *
     * @param array<int, int|string> $ids
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>}
     */
    public function sync(array $ids): array
    {
        $current = $this->pivot()->where($this->foreignPivotKey, $this->parent->getKey())->pluck($this->relatedPivotKey)->all();
        $current = array_map('strval', $current);
        $wanted = array_map('strval', $ids);

        $detach = array_values(array_diff($current, $wanted));
        $attach = array_values(array_diff($wanted, $current));

        if ($detach !== []) {
            $this->detach($detach);
        }
        $this->attach($attach);

        return ['attached' => $attach, 'detached' => $detach];
    }

    private function pivot(): QueryBuilder
    {
        return new QueryBuilder($this->parent::connection(), $this->pivotTable);
    }
}
