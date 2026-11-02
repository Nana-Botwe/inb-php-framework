<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Support\Str;

/**
 * $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 */
final class ForeignIdColumnDefinition extends ColumnDefinition
{
    private ?ForeignKeyDefinition $foreign = null;

    /** Add a foreign key; the table is guessed from the column name (user_id -> users). */
    public function constrained(?string $table = null, string $column = 'id'): static
    {
        $table ??= Str::plural(preg_replace('/_id$/', '', $this->name) ?? $this->name);
        $this->foreign = $this->blueprint->foreign($this->name)->references($column)->on($table);

        return $this;
    }

    public function cascadeOnDelete(): static
    {
        $this->foreign?->cascadeOnDelete();

        return $this;
    }

    public function nullOnDelete(): static
    {
        $this->foreign?->nullOnDelete();

        return $this;
    }

    public function restrictOnDelete(): static
    {
        $this->foreign?->restrictOnDelete();

        return $this;
    }

    public function cascadeOnUpdate(): static
    {
        $this->foreign?->onUpdate('cascade');

        return $this;
    }
}
