<?php

declare(strict_types=1);

namespace Core\Database\Relations;

/**
 * One-to-one:  public function profile(): HasOne { return $this->hasOne(Profile::class); }
 */
class HasOne extends HasMany
{
    public function getResults(): mixed
    {
        return $this->query->first();
    }

    protected function wrap(array $results): mixed
    {
        return $results[0] ?? null;
    }
}
