<?php

declare(strict_types=1);

namespace Core;

/**
 * Add "use SoftDeletes;" to a model (and $table->softDeletes() to its migration):
 * delete() then only sets deleted_at, and normal queries hide those rows.
 *
 *   Post::withTrashed()->get();  Post::onlyTrashed()->get();  $post->restore();  $post->forceDelete();
 */
trait SoftDeletes
{
    public function trashed(): bool
    {
        return ($this->getAttributes()['deleted_at'] ?? null) !== null;
    }

    public function restore(): bool
    {
        $this->deleted_at = null;

        return $this->save();
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $this->newQueryForThis()->withTrashed()->forceDelete();
        $this->exists = false;

        return true;
    }
}
