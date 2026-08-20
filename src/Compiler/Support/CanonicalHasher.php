<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Support;

use JsonSerializable;

/**
 * Stable content hashing for schema fragments.
 *
 * Hashes must be identical across two compiles of an unchanged application, and
 * must change when, and only when, something meaningful to a payload editor
 * changes. That means the canonical encoding is order-independent for object
 * keys, and every volatile field (generated_at, snapshots, previously computed
 * hashes) is stripped by the caller before hashing.
 */
final class CanonicalHasher
{
    public const ALGORITHM = 'sha256';

    public const LENGTH = 12;

    /**
     * @param mixed $value
     */
    public static function hash($value): string
    {
        $digest = hash(self::ALGORITHM, self::canonicalize($value));

        return self::ALGORITHM.':'.substr($digest, 0, self::LENGTH);
    }

    /**
     * Deterministic JSON encoding: object keys sorted recursively, no whitespace.
     *
     * List arrays keep their order, since order is meaningful for things like
     * enum cases and required lists. Callers that want order-independence there
     * must sort before hashing.
     *
     * @param mixed $value
     */
    public static function canonicalize($value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn ($item) => self::normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(static fn ($item) => self::normalize($item), $value);
    }

    /**
     * Remove keys from an array recursively, used to strip volatile fields
     * (x-laravel.hash, snapshot, generated_at) before hashing.
     *
     * Paths are dot-delimited and matched against the full path from the root,
     * with "*" matching a single segment. Bare key names match at any depth.
     *
     * @param array<mixed> $value
     * @param array<int, string> $paths
     * @return array<mixed>
     */
    public static function without(array $value, array $paths): array
    {
        [$anywhere, $rooted] = self::partitionPaths($paths);

        return self::strip($value, $anywhere, $rooted, '');
    }

    /**
     * @param array<int, string> $paths
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function partitionPaths(array $paths): array
    {
        $anywhere = [];
        $rooted = [];

        foreach ($paths as $path) {
            if (str_contains($path, '.')) {
                $rooted[] = $path;
            } else {
                $anywhere[] = $path;
            }
        }

        return [$anywhere, $rooted];
    }

    /**
     * @param array<mixed> $value
     * @param array<int, string> $anywhere
     * @param array<int, string> $rooted
     * @return array<mixed>
     */
    private static function strip(array $value, array $anywhere, array $rooted, string $prefix): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($key) && in_array($key, $anywhere, true)) {
                continue;
            }

            if (self::matchesAny($path, $rooted)) {
                continue;
            }

            $result[$key] = is_array($item)
                ? self::strip($item, $anywhere, $rooted, $path)
                : $item;
        }

        return $result;
    }

    /**
     * @param array<int, string> $patterns
     */
    private static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            $regex = '#^'.str_replace('\*', '[^.]+', preg_quote($pattern, '#')).'$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
