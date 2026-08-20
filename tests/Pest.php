<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Security', 'Unit');

/**
 * Recursively collect every value stored under $key, at any depth.
 *
 * Most assertions about the compiled document are "does this fact appear
 * anywhere in here", and walking by hand in each test buries the assertion.
 *
 * @param array<mixed> $haystack
 * @return array<int, mixed>
 */
function collectDeep(array $haystack, string $key): array
{
    $found = [];

    array_walk_recursive($haystack, static function ($value, $index) use (&$found, $key): void {
        if ($index === $key) {
            $found[] = $value;
        }
    });

    // array_walk_recursive skips arrays, so nested array values need a second pass.
    $walk = function (array $node) use (&$walk, &$found, $key): void {
        foreach ($node as $index => $value) {
            if ($index === $key && is_array($value)) {
                $found[] = $value;
            }

            if (is_array($value)) {
                $walk($value);
            }
        }
    };

    $walk($haystack);

    return $found;
}

/**
 * @param array<string, mixed> $document
 * @return array<string, mixed>|null
 */
function endpoint(array $document, string $id): ?array
{
    foreach ($document['endpoints'] ?? [] as $endpoint) {
        if ($endpoint['id'] === $id) {
            return $endpoint;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $document
 * @return array<string, mixed>|null
 */
function component(array $document, string $name): ?array
{
    return $document['components']['data_objects'][$name] ?? null;
}

/**
 * @param array<string, mixed> $document
 * @return array<int, string>
 */
function warningCodes(array $document): array
{
    return array_map(
        static fn (array $warning): string => $warning['code'],
        $document['diagnostics']['warnings'] ?? []
    );
}
