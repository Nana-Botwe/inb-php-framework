<?php

declare(strict_types=1);

namespace Core\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Fluent wrapper around arrays (like Laravel collections). Query results are returned as Collections.
 *
 *   collect([1, 2, 3])->map(fn ($n) => $n * 2)->filter(fn ($n) => $n > 2)->values()->all();  // [4, 6]
 *   $users->pluck('email');  $users->where('role', 'admin')->count();  $posts->groupBy('user_id');
 *
 * @implements ArrayAccess<int|string, mixed>
 * @implements IteratorAggregate<int|string, mixed>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @param array<int|string, mixed> $items */
    public function __construct(protected array $items = [])
    {
    }

    public static function make(mixed $items = []): static
    {
        return new static($items instanceof self ? $items->all() : Arr::wrap($items));
    }

    /** @return array<int|string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);

        return new static(array_combine($keys, array_map($callback, $this->items, $keys)));
    }

    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            foreach ($callback($value, $key) as $k => $v) {
                $result[$k] = $v;
            }
        }

        return new static($result);
    }

    public function filter(?callable $callback = null): static
    {
        return new static($callback === null
            ? array_filter($this->items)
            : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(static fn ($value, $key) => !$callback($value, $key));
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }

        return $this;
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        return Arr::first($this->items, $callback, $default);
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        return Arr::first(array_reverse($this->items, true), $callback, $default);
    }

    public function pluck(string $value, ?string $key = null): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $v = Arr::get($item, $value);
            if ($key === null) {
                $result[] = $v;
            } else {
                $result[(string) Arr::get($item, $key)] = $v;
            }
        }

        return new static($result);
    }

    /** Filter items where a field equals (or compares to) a value: ->where('active', 1), ->where('age', '>', 18) */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        return $this->filter(static function ($item) use ($key, $operator, $value): bool {
            $actual = Arr::get($item, $key);

            return match ($operator) {
                '=', '==' => $actual == $value,
                '===' => $actual === $value,
                '!=', '<>' => $actual != $value,
                '>' => $actual > $value,
                '>=' => $actual >= $value,
                '<' => $actual < $value,
                '<=' => $actual <= $value,
                default => false,
            };
        });
    }

    /** @param array<mixed> $values */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(static fn ($item) => in_array(Arr::get($item, $key), $values));
    }

    public function keyBy(string|callable $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $result[is_callable($key) ? $key($item) : (string) Arr::get($item, $key)] = $item;
        }

        return new static($result);
    }

    public function groupBy(string|callable $key): static
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groups[is_callable($key) ? $key($item) : (string) Arr::get($item, $key)][] = $item;
        }

        return new static(array_map(static fn ($group) => new static($group), $groups));
    }

    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;
        uasort($items, static function ($a, $b) use ($key, $descending): int {
            $x = is_callable($key) ? $key($a) : Arr::get($a, $key);
            $y = is_callable($key) ? $key($b) : Arr::get($b, $key);

            return $descending ? $y <=> $x : $x <=> $y;
        });

        return new static($items);
    }

    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback === null ? asort($items) : uasort($items, $callback);

        return new static($items);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function unique(?string $key = null): static
    {
        $seen = [];

        return $this->filter(static function ($item) use ($key, &$seen): bool {
            $id = serialize($key === null ? $item : Arr::get($item, $key));
            if (isset($seen[$id])) {
                return false;
            }

            return $seen[$id] = true;
        });
    }

    public function chunk(int $size): static
    {
        return new static(array_map(static fn ($chunk) => new static($chunk), array_chunk($this->items, max(1, $size), true)));
    }

    public function take(int $limit): static
    {
        return new static($limit < 0 ? array_slice($this->items, $limit, null, true) : array_slice($this->items, 0, $limit, true));
    }

    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count, null, true));
    }

    /** @param array<mixed>|Collection $items */
    public function merge(array|self $items): static
    {
        return new static(array_merge($this->items, $items instanceof self ? $items->all() : $items));
    }

    public function push(mixed ...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    public function contains(mixed $key, mixed $value = null): bool
    {
        if (is_callable($key) && !is_string($key)) {
            return $this->first($key) !== null;
        }
        if (func_num_args() === 2) {
            return $this->where((string) $key, $value)->isNotEmpty();
        }

        return in_array($key, $this->items);
    }

    public function sum(string|callable|null $key = null): int|float
    {
        return array_sum($key === null ? $this->items : $this->map(
            static fn ($item) => is_callable($key) ? $key($item) : Arr::get($item, $key),
        )->all());
    }

    public function avg(string|callable|null $key = null): int|float|null
    {
        $count = $this->count();

        return $count === 0 ? null : $this->sum($key) / $count;
    }

    public function max(?string $key = null): mixed
    {
        $values = $key === null ? $this->items : $this->pluck($key)->all();

        return $values === [] ? null : max($values);
    }

    public function min(?string $key = null): mixed
    {
        $values = $key === null ? $this->items : $this->pluck($key)->all();

        return $values === [] ? null : min($values);
    }

    public function implode(string $glue, ?string $key = null): string
    {
        return implode($glue, $key === null ? $this->items : $this->pluck($key)->all());
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<int|string, mixed> */
    public function toArray(): array
    {
        return array_map(static fn ($item) => match (true) {
            $item instanceof self => $item->toArray(),
            is_object($item) && method_exists($item, 'toArray') => $item->toArray(),
            default => $item,
        }, $this->items);
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}
