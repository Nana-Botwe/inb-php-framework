<?php

declare(strict_types=1);

namespace Core\View;

/**
 * The $loop variable inside @foreach / @forelse:
 * $loop->index, iteration, remaining, count, first, last, even, odd, depth, parent
 */
final class Loop
{
    public int $index = -1;

    public int $iteration = 0;

    public ?int $remaining = null;

    public bool $first = false;

    public bool $last = false;

    public bool $even = false;

    public bool $odd = false;

    public int $depth;

    public function __construct(public readonly ?int $count, public readonly ?Loop $parent = null)
    {
        $this->depth = $parent === null ? 1 : $parent->depth + 1;
    }

    public function tick(): void
    {
        $this->index++;
        $this->iteration++;
        $this->first = $this->index === 0;
        $this->even = $this->iteration % 2 === 0;
        $this->odd = !$this->even;

        if ($this->count !== null) {
            $this->remaining = $this->count - $this->iteration;
            $this->last = $this->iteration === $this->count;
        }
    }
}
