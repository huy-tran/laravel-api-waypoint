<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

/**
 * Canonical key order for a property schema.
 *
 * Two reasons this exists rather than leaving keys in insertion order. First,
 * rule-order independence: ['nullable','string','max:50'] and
 * ['max:50','string','nullable'] must produce the same schema, and "the same"
 * includes key order or the golden file churns on a harmless reordering. Second,
 * a human reading the document gets the JSON Schema keywords first and the
 * extension blocks last, which is the order they want to read them in.
 */
final class SchemaKeywords
{
    private const SCHEMA = [
        '$schema', '$ref', 'oneOf', 'title', 'type', 'format', 'enum', 'const', 'default',
        'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minItems', 'maxItems', 'uniqueItems', 'items',
        'minProperties', 'maxProperties', 'additionalProperties', 'required', 'properties',
        'description', 'x-laravel', 'x-faker',
    ];

    private const LARAVEL = [
        'class', 'property', 'input_name', 'rules', 'optional', 'nullable', 'present_only',
        'filled', 'synthetic', 'mirrors', 'default', 'description', 'enum_class',
        'data_collection_of', 'cast_from', 'upload', 'json', 'decimal', 'date_format',
        'date_bounds', 'not_regex', 'not_in', 'exists', 'unique', 'conditional_rules', 'hash',
    ];

    private const FAKER = [
        'strategy', 'reason', 'pattern', 'format', 'separator', 'values', 'length',
        'min', 'max', 'count', 'range', 'reference', 'mirrors', 'unique',
        'include_probability', 'true_probability', 'required_when', 'omit_when',
    ];

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function order(array $schema): array
    {
        return self::apply($schema, self::SCHEMA);
    }

    /**
     * @param array<string, mixed> $laravel
     * @return array<string, mixed>
     */
    public static function orderLaravel(array $laravel): array
    {
        return self::apply($laravel, self::LARAVEL);
    }

    /**
     * @param array<string, mixed> $faker
     * @return array<string, mixed>
     */
    public static function orderFaker(array $faker): array
    {
        return self::apply($faker, self::FAKER);
    }

    /**
     * Known keys in declared order, then anything unrecognised sorted by name so
     * the result is deterministic whatever order it was built in.
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $order
     * @return array<string, mixed>
     */
    private static function apply(array $values, array $order): array
    {
        $ordered = [];

        foreach ($order as $key) {
            if (array_key_exists($key, $values)) {
                $ordered[$key] = $values[$key];
                unset($values[$key]);
            }
        }

        ksort($values);

        return $ordered + $values;
    }
}
