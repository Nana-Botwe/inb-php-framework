<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * A column inside a Blueprint. Modifiers are chainable:
 *   $table->string('email')->unique();  $table->integer('votes')->default(0)->unsigned();
 */
class ColumnDefinition
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    public bool $unsigned = false;

    public bool $autoIncrement = false;

    public bool $primary = false;

    public bool $useCurrent = false;

    public bool $useCurrentOnUpdate = false;

    public ?string $comment = null;

    public ?string $after = null;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly Blueprint $blueprint,
        public readonly string $name,
        public readonly string $type,
        public readonly array $options = [],
    ) {
    }

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;

        return $this;
    }

    public function autoIncrement(): static
    {
        $this->autoIncrement = true;

        return $this;
    }

    public function primary(): static
    {
        $this->primary = true;

        return $this;
    }

    public function unique(?string $indexName = null): static
    {
        $this->blueprint->unique([$this->name], $indexName);

        return $this;
    }

    public function index(?string $indexName = null): static
    {
        $this->blueprint->index([$this->name], $indexName);

        return $this;
    }

    /** DEFAULT CURRENT_TIMESTAMP */
    public function useCurrent(): static
    {
        $this->useCurrent = true;

        return $this;
    }

    /** ON UPDATE CURRENT_TIMESTAMP (MySQL only) */
    public function useCurrentOnUpdate(): static
    {
        $this->useCurrentOnUpdate = true;

        return $this;
    }

    public function comment(string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    /** Place the column after another one (MySQL, when altering a table). */
    public function after(string $column): static
    {
        $this->after = $column;

        return $this;
    }
}
