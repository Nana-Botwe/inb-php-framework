<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;

/**
 * Add "use HasFactory;" to a model to enable Model::factory().
 * The factory class is Database\Factories\{ModelName}Factory.
 */
trait HasFactory
{
    public static function factory(int $count = 1): Factory
    {
        $class = 'Database\\Factories\\' . (new \ReflectionClass(static::class))->getShortName() . 'Factory';

        if (!class_exists($class)) {
            throw new RuntimeException("Factory [$class] not found. Create it with: php console make:factory");
        }

        return $class::new()->count($count);
    }
}
