<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
 */
final class ForeignKeyDefinition
{
    public string $references = 'id';

    public string $on = '';

    public string $onDelete = 'RESTRICT';

    public string $onUpdate = 'CASCADE';

    public function __construct(public readonly string $column, public string $name)
    {
    }

    public function references(string $column): self
    {
        $this->references = $column;

        return $this;
    }

    public function on(string $table): self
    {
        $this->on = $table;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    private static function action(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'], true)) {
            throw new \InvalidArgumentException("Invalid foreign key action [$action].");
        }

        return $action;
    }
}
