<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Model;
use Core\Support\Collection;

/**
 * Model factories for seeding and tests:
 *
 *   class UserFactory extends Factory {
 *       protected string $model = User::class;
 *       public function definition(): array { return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail()]; }
 *   }
 *   User::factory()->count(10)->create();
 *   User::factory()->state(['role' => 'admin'])->create();
 */
abstract class Factory
{
    /** @var class-string<Model> */
    protected string $model;

    private int $count = 1;

    /** @var array<int, array<string, mixed>|callable> */
    private array $states = [];

    /** @return array<string, mixed> */
    abstract public function definition(): array;

    public static function new(): static
    {
        return new static();
    }

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(1, $count);

        return $clone;
    }

    /** @param array<string, mixed>|callable(array): array $state */
    public function state(array|callable $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Build models without saving.
     *
     * @param array<string, mixed> $attributes
     */
    public function make(array $attributes = []): Model|Collection
    {
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = (new $this->model())->forceFill($this->attributes($attributes));
        }

        return $this->count === 1 ? $models[0] : new Collection($models);
    }

    /**
     * Build and save models.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes = []): Model|Collection
    {
        $result = $this->make($attributes);
        foreach ($result instanceof Collection ? $result : [$result] as $model) {
            $model->save();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides): array
    {
        $attributes = $this->definition();
        foreach ($this->states as $state) {
            $attributes = array_merge($attributes, is_callable($state) ? $state($attributes) : $state);
        }

        return array_merge($attributes, $overrides);
    }
}
