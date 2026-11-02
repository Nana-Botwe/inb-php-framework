<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Array helpers with dot-notation: Arr::get($data, 'user.address.city').
 */
final class Arr
{
    public static function get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $target;
        }

        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif ($target instanceof \ArrayAccess && isset($target[$segment])) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default instanceof \Closure ? $default() : $default;
            }
        }

        return $target;
    }

    /** @param array<mixed> $array */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $target = &$array;
        foreach (explode('.', $key) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    /** @param array<mixed> $array */
    public static function has(array $array, string $key): bool
    {
        $marker = new \stdClass();

        return self::get($array, $key, $marker) !== $marker;
    }

    /**
     * @param array<mixed> $array
     * @param string[] $keys
     * @return array<mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * @param array<mixed> $array
     * @param string[] $keys
     * @return array<mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];
        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                array_push($result, ...self::flatten($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /** @return array<mixed> */
    public static function wrap(mixed $value): array
    {
        return $value === null ? [] : (is_array($value) ? $value : [$value]);
    }

    /** @param array<mixed> $array */
    public static function isAssoc(array $array): bool
    {
        return !array_is_list($array);
    }

    /** @param array<mixed> $array */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }
}
