<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Faker;

use Hygo\ApiWaypoint\Attributes\WaypointFaker;
use Hygo\ApiWaypoint\Compiler\Data\RegexTranslator;
use Illuminate\Support\Str;

/**
 * Produces the x-faker block for every property.
 *
 * The package never names a generator method from any particular library. It
 * emits an abstract strategy, and the Central App decides how to realise it. That
 * separation is the whole reason the two codebases can be built independently.
 *
 * Precedence runs highest first, and the first level to supply a strategy wins it.
 * Auxiliary keys (unique, include_probability, count) are merged from every level
 * that has something to say, with earlier levels still winning per key.
 */
class FakerHintResolver
{
    /** @var array<string, array<string, mixed>> */
    protected array $overrides;

    /** @var array<string, array<string, mixed>> */
    protected array $nameHints;

    protected float $defaultIncludeProbability;

    protected int $arrayCountCeiling;

    /**
     * @param array<string, mixed> $config The api-waypoint.faker config block.
     */
    public function __construct(array $config = [])
    {
        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = (array) ($config['overrides'] ?? []);
        $this->overrides = $overrides;

        /** @var array<string, array<string, mixed>> $hints */
        $hints = (array) ($config['name_hints'] ?? []);
        $this->nameHints = array_merge(StrategyVocabulary::defaultNameHints(), $hints);

        $this->defaultIncludeProbability = (float) ($config['default_include_probability'] ?? 0.5);
        $this->arrayCountCeiling = (int) ($config['array_count_ceiling'] ?? 3);
    }

    /**
     * @param array<string, mixed> $schema The property schema built so far.
     * @param array<string, mixed> $ruleHints Hints the RuleMapper derived.
     * @return array<string, mixed>
     */
    public function resolve(
        string $component,
        string $property,
        array $schema,
        array $ruleHints = [],
        ?WaypointFaker $attribute = null,
        bool $optional = false,
        bool $nullable = false,
    ): array {
        $hint = [];

        // 1. Config overrides, most specific key first.
        $hint = $this->fill($hint, $this->override($component, $property));

        // 2. The #[WaypointFaker] attribute on the property.
        $hint = $this->fill($hint, $attribute?->toArray() ?? []);

        // 3. Rule-derived hints: exists -> reference, a non-sibling field reference
        //    -> unresolvable, date bounds, timezone, mirror.
        $hint = $this->fill($hint, $ruleHints);

        if (! isset($hint['strategy'])) {
            $hint = $this->fill($hint, $this->fromSchema($schema, $property));
        }

        // 9. Nothing matched. Saying so beats emitting a plausible wrong value.
        if (! isset($hint['strategy'])) {
            $hint['strategy'] = 'unresolvable';
            $hint['reason'] = sprintf(
                'No generation strategy could be inferred for [%s]. Add a #[WaypointFaker] attribute or a faker.overrides entry.',
                $property
            );
        }

        return $this->decorate($hint, $schema, $optional, $nullable);
    }

    /**
     * Levels 4 to 8: enum, pattern, format, property name, resolved JSON type.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function fromSchema(array $schema, string $property): array
    {
        // 4. An enum is the most constrained thing a property can be.
        if (isset($schema['enum'])) {
            return ['strategy' => 'enum'];
        }

        // 5. A pattern, but only when a faithful mask can be derived from it.
        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $mask = RegexTranslator::toMask($schema['pattern']);

            return $mask !== null
                ? ['strategy' => 'pattern', 'pattern' => $mask]
                : [
                    'strategy' => 'unresolvable',
                    'reason' => 'The pattern is richer than a generation mask can express. Pin a value.',
                ];
        }

        // 6. A JSON Schema format maps straight onto the vocabulary.
        if (isset($schema['format']) && is_string($schema['format'])) {
            $fromFormat = match ($schema['format']) {
                'email' => ['strategy' => 'internet.email'],
                'uri', 'url' => ['strategy' => 'url'],
                'uuid' => ['strategy' => 'uuid'],
                'date-time', 'date' => ['strategy' => 'date', 'format' => 'iso8601'],
                'ipv4', 'ipv6', 'ip' => ['strategy' => 'pattern', 'pattern' => '###.###.###.###'],
                default => [],
            };

            if ($fromFormat !== []) {
                return $fromFormat;
            }
        }

        // 7. Property name heuristics.
        if (($named = $this->fromName($property)) !== []) {
            return $named;
        }

        // 8. Fall back to the resolved JSON type.
        return $this->fromType($schema);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromName(string $property): array
    {
        $candidates = [
            $property,
            Str::snake($property),
            // last_name matches "customer_last_name" too, which is the common case
            // in flattened payloads.
        ];

        foreach ($candidates as $candidate) {
            if (isset($this->nameHints[$candidate])) {
                return $this->nameHints[$candidate];
            }
        }

        $snake = Str::snake($property);

        foreach ($this->nameHints as $name => $hint) {
            if (str_ends_with($snake, '_'.$name)) {
                return $hint;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function fromType(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = $this->firstNonNull($type);
        }

        if (isset($schema['$ref']) || isset($schema['oneOf'])) {
            return ['strategy' => 'collection'];
        }

        return match ($type) {
            'integer' => ['strategy' => 'int'],
            'number' => ['strategy' => 'float'],
            'boolean' => ['strategy' => 'boolean'],
            'string' => ['strategy' => 'sentence'],
            'array' => ['strategy' => 'collection'],
            'object' => ['strategy' => 'key_value_map'],
            default => [],
        };
    }

    /**
     * @param array<int, mixed> $types
     */
    protected function firstNonNull(array $types): ?string
    {
        foreach ($types as $type) {
            if ($type !== 'null' && is_string($type)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * "Module.DataClass.property" beats "*.property", so an app can set a global
     * rule for every email field and still special-case one of them.
     *
     * @return array<string, mixed>
     */
    protected function override(string $component, string $property): array
    {
        foreach (["{$component}.{$property}", "*.{$property}"] as $key) {
            if (isset($this->overrides[$key])) {
                return (array) $this->overrides[$key];
            }
        }

        return [];
    }

    /**
     * Fill only the keys the higher-precedence levels left empty.
     *
     * @param array<string, mixed> $hint
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    protected function fill(array $hint, array $candidate): array
    {
        foreach ($candidate as $key => $value) {
            if (! array_key_exists($key, $hint)) {
                $hint[$key] = $value;
            }
        }

        return $hint;
    }

    /**
     * Bounds, counts and probabilities, which apply whatever the strategy is.
     *
     * @param array<string, mixed> $hint
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function decorate(array $hint, array $schema, bool $optional, bool $nullable): array
    {
        $laravel = $schema['x-laravel'] ?? [];

        if (($hint['strategy'] ?? null) === 'reference' && ! isset($hint['reference']) && isset($laravel['exists'])) {
            $hint['reference'] = $laravel['exists'];
        }

        if (isset($hint['constraint'])) {
            $hint['reference'] = array_merge($hint['reference'] ?? [], ['constraint' => $hint['constraint']]);
            unset($hint['constraint']);
        }

        if (isset($laravel['unique']) && ! isset($hint['unique'])) {
            $hint['unique'] = true;
        }

        // include_probability is what makes repeated generation produce genuinely
        // different datasets rather than the same maximal payload every time.
        if (($optional || $nullable) && ! isset($hint['include_probability'])) {
            $hint['include_probability'] = $this->defaultIncludeProbability;
        }

        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        if (in_array('array', $types, true)) {
            $hint['count'] ??= $this->countFor($schema);
        }

        if (in_array('integer', $types, true) || in_array('number', $types, true)) {
            $hint = $this->applyNumericBounds($hint, $schema);
        }

        if (in_array('string', $types, true) && isset($schema['maxLength']) && ! isset($hint['max'])) {
            $hint['max'] = $schema['maxLength'];
        }

        return $hint;
    }

    /**
     * A maxItems of 50 must not mean "generate 50 items by default". The ceiling
     * keeps generated payloads reviewable.
     *
     * @param array<string, mixed> $schema
     * @return array{min: int, max: int}
     */
    protected function countFor(array $schema): array
    {
        $min = (int) ($schema['minItems'] ?? 0);
        $max = (int) ($schema['maxItems'] ?? $this->arrayCountCeiling);

        $max = min($max, max($min, $this->arrayCountCeiling));

        return ['min' => $min, 'max' => max($min, $max)];
    }

    /**
     * @param array<string, mixed> $hint
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function applyNumericBounds(array $hint, array $schema): array
    {
        $schemaMin = $schema['minimum'] ?? (isset($schema['exclusiveMinimum']) ? $schema['exclusiveMinimum'] + 1 : null);
        $schemaMax = $schema['maximum'] ?? (isset($schema['exclusiveMaximum']) ? $schema['exclusiveMaximum'] - 1 : null);

        if ($schemaMin === null && $schemaMax === null) {
            return $hint;
        }

        $hintMin = $hint['min'] ?? null;
        $hintMax = $hint['max'] ?? null;

        // A schema bound is a hard constraint; a heuristic's range is a suggestion.
        // Where they overlap, take the intersection, because the heuristic knows
        // something useful about the field. Where they do not overlap at all, the
        // heuristic is simply wrong about this property and the schema stands on its
        // own: narrowing to the boundary would generate the same value every time.
        $overlaps = $hintMin !== null
            && $hintMax !== null
            && ($schemaMax === null || $hintMin <= $schemaMax)
            && ($schemaMin === null || $hintMax >= $schemaMin);

        if ($hintMin === null || $hintMax === null || $overlaps) {
            if ($schemaMin !== null) {
                $hint['min'] = $hintMin === null ? $schemaMin : max($hintMin, $schemaMin);
            }

            if ($schemaMax !== null) {
                $hint['max'] = $hintMax === null ? $schemaMax : min($hintMax, $schemaMax);
            }

            return $hint;
        }

        unset($hint['min'], $hint['max']);

        if ($schemaMin !== null) {
            $hint['min'] = $schemaMin;
        }

        if ($schemaMax !== null) {
            $hint['max'] = $schemaMax;
        }

        return $hint;
    }
}
