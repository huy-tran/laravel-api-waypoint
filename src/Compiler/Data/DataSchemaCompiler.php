<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

use BackedEnum;
use Hygo\ApiWaypoint\Attributes\WaypointFaker;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\ModuleResolver;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Types\NamedType;
use Spatie\LaravelData\Support\Types\Type;
use Spatie\LaravelData\Support\Types\UnionType;
use Throwable;
use UnitEnum;

/**
 * Compiles a Spatie Data class into a JSON Schema component.
 *
 * Two sources are merged, because neither is complete on its own: Spatie's own
 * DataClass structure knows types, nullability, Optional, defaults, attributes and
 * the input name mapper, while getValidationRules() is the only place rules added
 * in a rules() method show up. Attribute-derived facts win on conflict, and the
 * conflict is reported rather than swallowed.
 */
class DataSchemaCompiler
{
    public function __construct(
        protected DataConfig $dataConfig,
        protected RuleMapper $ruleMapper,
        protected FakerHintResolver $fakerHints,
        protected ComponentRegistry $registry,
        protected ModuleResolver $modules,
        protected Diagnostics $diagnostics,
    ) {}

    /**
     * Compile the class and return its component name, or null when it cannot be
     * compiled at all.
     */
    public function compile(string $class): ?string
    {
        if (! $this->isDataClass($class)) {
            $this->diagnostics->warn(
                WarningCode::UNCOMPILABLE_DATA_CLASS,
                sprintf('[%s] is not a Spatie Data class.', $class)
            );

            return null;
        }

        $module = $this->modules->resolve($class)['name'];
        $component = $this->registry->nameFor($class, $module);

        if ($this->registry->knows($class)) {
            return $component;
        }

        // Cycle guard: a self-referencing Data class emits a $ref back to the
        // component that is still being built, rather than recursing forever.
        if ($this->registry->isCompiling($class)) {
            $this->registry->markRecursive($class);

            return $component;
        }

        $this->registry->beginCompiling($class);

        try {
            $schema = $this->build($class, $component);
        } catch (Throwable $exception) {
            $this->diagnostics->warn(
                WarningCode::UNCOMPILABLE_DATA_CLASS,
                sprintf('[%s] could not be compiled: %s', $class, $exception->getMessage()),
                ['component' => $component]
            );

            $this->registry->finishCompiling($class);

            return null;
        }

        $this->registry->finishCompiling($class);
        $this->registry->register($component, $schema);

        if ($this->registry->isRecursive($class)) {
            $this->diagnostics->warn(
                WarningCode::RECURSIVE_DATA_CLASS,
                sprintf('[%s] references itself. The cycle is cut with a $ref.', $class),
                ['component' => $component]
            );
        }

        return $component;
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(string $class, string $component): array
    {
        $dataClass = $this->dataConfig->getDataClass($class);
        $rules = $this->validationRules($class, $component);

        $properties = [];
        $required = [];
        $siblings = $this->siblingKeys($dataClass);

        /** @var DataProperty $property */
        foreach ($dataClass->properties as $property) {
            // Computed and Lazy properties are output-only. Putting them in an input
            // schema tells the Central App to send a field the endpoint ignores.
            if ($property->computed || $property->type->lazyType !== null) {
                continue;
            }

            if ($property->attributes->has(WithoutValidation::class)) {
                // Still part of the payload, just not validated: describe it from the
                // PHP type alone.
                $propertyRules = [];
            } else {
                $propertyRules = $this->rulesFor($rules, $property, $component);
            }

            $key = $property->inputMappedName ?? $property->name;

            $compiled = $this->compileProperty($property, $propertyRules, $rules, $component, $siblings);

            if ($compiled === null) {
                continue;
            }

            [$schema, $isRequired, $confirmed] = $compiled;

            $properties[$key] = $schema;

            if ($isRequired) {
                $required[] = $key;
            }

            // "confirmed" implies a sibling the Data class never declares, but the
            // endpoint will reject the payload without it.
            if ($confirmed) {
                $properties[$key.'_confirmation'] = $this->confirmationSibling($schema, $key);

                if ($isRequired) {
                    $required[] = $key.'_confirmation';
                }
            }
        }

        sort($required);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => class_basename($class),
            'x-laravel' => ['class' => $class],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            // An object rather than [] when empty, so it serialises as {} and the
            // contract's "properties is an object" rule holds for a bodyless Data class.
            'properties' => $properties ?: (object) [],
        ];
    }

    /**
     * @param array<string, mixed> $rules The whole rule set, for nested lookups.
     * @param array<int, mixed> $propertyRules
     * @param array<int, string> $siblings
     * @return array{0: array<string, mixed>, 1: bool, 2: bool}|null
     */
    protected function compileProperty(
        DataProperty $property,
        array $propertyRules,
        array $rules,
        string $component,
        array $siblings
    ): ?array {
        $key = $property->inputMappedName ?? $property->name;

        $typeFacts = $this->typeFacts($property, $component, $key);

        $mapped = $this->ruleMapper->map($propertyRules, $typeFacts['type'] ?? null, $siblings);

        foreach ($mapped->warnings as $warning) {
            $this->diagnostics->warn($warning['code'], $warning['detail'], [
                'component' => $component,
                'property' => $key,
            ]);
        }

        $schema = $this->mergeTypeAndRules($typeFacts, $mapped, $component, $key);

        // Spatie's own structure is authoritative for these three, because they are
        // properties of the PHP type rather than of the rule string.
        $optional = $property->type->isOptional || $mapped->optional;
        $nullable = $property->type->isNullable || $mapped->nullable;
        $required = $mapped->required && ! $optional;

        // A property with a default value is never required over the wire.
        if ($property->hasDefaultValue && $property->defaultValue !== null) {
            $schema['default'] = $this->normaliseDefault($property->defaultValue);
            $required = false;
        }

        if ($nullable) {
            $schema = $this->makeNullable($schema);
        }

        $laravel = array_merge($schema['x-laravel'] ?? [], $mapped->laravel);
        $laravel['property'] = $property->name;

        if ($property->inputMappedName !== null && $property->inputMappedName !== $property->name) {
            $laravel['input_name'] = $property->inputMappedName;
        }

        if ($mapped->rules !== []) {
            $laravel['rules'] = $mapped->rules;
        }

        if ($optional) {
            $laravel['optional'] = true;
        }

        if ($nullable) {
            $laravel['nullable'] = true;
        }

        // A null default on a nullable property says nothing the nullable flag has
        // not already said, and it clutters every optional field in the document.
        if ($property->hasDefaultValue && $property->defaultValue !== null) {
            $laravel['default'] = $this->normaliseDefault($property->defaultValue);
        }

        $schema['x-laravel'] = $this->orderLaravel($laravel);

        if ($mapped->multipart) {
            $schema['x-laravel']['upload'] = true;
        }

        $schema['x-faker'] = $this->fakerHints->resolve(
            component: $component,
            property: $key,
            schema: $schema,
            ruleHints: $mapped->faker,
            attribute: $property->attributes->first(WaypointFaker::class),
            optional: $optional,
            nullable: $nullable,
        );

        return [$this->order($schema), $required, $mapped->confirmed];
    }

    /**
     * Facts derived from the PHP type, before rules narrow them.
     *
     * @return array<string, mixed>
     */
    protected function typeFacts(DataProperty $property, string $component, string $key): array
    {
        $kind = $property->type->kind;

        // A nested Data class: recurse and reference.
        if ($kind->isDataObject() && $property->type->dataClass !== null) {
            $nested = $this->compile($property->type->dataClass);

            return $nested === null
                ? ['type' => 'object']
                : ['$ref' => $this->registry->ref($nested)];
        }

        // A DataCollection, or an array annotated with #[DataCollectionOf].
        if ($kind->isDataCollectable() && $property->type->dataClass !== null) {
            $nested = $this->compile($property->type->dataClass);

            return [
                'type' => 'array',
                'items' => $nested === null
                    ? ['type' => 'object']
                    : ['$ref' => $this->registry->ref($nested)],
                'x-laravel' => ['data_collection_of' => $property->type->dataClass],
            ];
        }

        $accepted = $this->namedTypes($property->type->type);

        foreach ($accepted as $type) {
            if ($type === 'null') {
                continue;
            }

            if (CastInputTypes::isBuiltin($type)) {
                $builtin = CastInputTypes::builtin($type);

                if ($builtin === 'array' && $property->type->iterableItemType !== null) {
                    // array<string, string> arrives as a JSON object, not a list, and
                    // describing it as an array would have the Central App send [].
                    return $property->type->iterableKeyType === 'string'
                        ? $this->mapOf($property->type->iterableItemType)
                        : $this->arrayOf($property->type->iterableItemType);
                }

                return $builtin === null ? [] : ['type' => $builtin];
            }

            if (enum_exists($type)) {
                return [
                    'type' => EnumReader::jsonType($type),
                    'enum' => EnumReader::values($type),
                    'x-laravel' => ['enum_class' => $type],
                ];
            }

            if (CastInputTypes::isUpload($type)) {
                return ['type' => 'string', 'x-laravel' => ['upload' => true]];
            }

            if (($fragment = CastInputTypes::for($type)) !== null) {
                return $fragment;
            }

            // An unknown class type. A cast somewhere widens it to something, and
            // string is the commonest answer, but the compiler is guessing and says so.
            $this->diagnostics->warn(
                WarningCode::CAST_INPUT_ASSUMED,
                sprintf('[%s] has no cast-input mapping; assuming the endpoint accepts a string.', $type),
                ['component' => $component, 'property' => $key]
            );

            return ['type' => 'string', 'x-laravel' => ['cast_from' => $type]];
        }

        return [];
    }

    /**
     * A string-keyed iterable: a JSON object whose values share one type.
     *
     * @return array<string, mixed>
     */
    protected function mapOf(string $valueType): array
    {
        $value = $this->arrayOf($valueType)['items'] ?? ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function arrayOf(string $itemType): array
    {
        if (enum_exists($itemType)) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => EnumReader::jsonType($itemType),
                    'enum' => EnumReader::values($itemType),
                    'x-laravel' => ['enum_class' => $itemType],
                ],
            ];
        }

        if (CastInputTypes::isBuiltin($itemType)) {
            $builtin = CastInputTypes::builtin($itemType);

            return $builtin === null
                ? ['type' => 'array']
                : ['type' => 'array', 'items' => ['type' => $builtin]];
        }

        if ($this->isDataClass($itemType)) {
            $nested = $this->compile($itemType);

            return [
                'type' => 'array',
                'items' => $nested === null ? ['type' => 'object'] : ['$ref' => $this->registry->ref($nested)],
                'x-laravel' => ['data_collection_of' => $itemType],
            ];
        }

        return ['type' => 'array'];
    }

    /**
     * @return array<int, string>
     */
    protected function namedTypes(Type $type): array
    {
        if ($type instanceof NamedType) {
            return [$type->name];
        }

        if ($type instanceof UnionType) {
            $names = [];

            foreach ($type->types as $member) {
                $names = array_merge($names, $this->namedTypes($member));
            }

            return $names;
        }

        return [];
    }

    /**
     * Attribute-derived facts (the PHP type) take precedence over rule-derived
     * facts on conflict, and the conflict is reported: it usually means a rules()
     * method disagrees with the property signature, which is a real bug.
     *
     * @param array<string, mixed> $typeFacts
     * @return array<string, mixed>
     */
    protected function mergeTypeAndRules(array $typeFacts, MappedRules $mapped, string $component, string $key): array
    {
        // A $ref carries its whole schema by reference; merging keywords alongside
        // it is meaningless in JSON Schema, so rules only contribute x-laravel.
        if (isset($typeFacts['$ref'])) {
            return $typeFacts;
        }

        $schema = $mapped->schema;

        foreach ($typeFacts as $keyword => $value) {
            if ($keyword === 'x-laravel') {
                $schema['x-laravel'] = array_merge($schema['x-laravel'] ?? [], $value);

                continue;
            }

            if ($keyword === 'type' && isset($schema['type']) && $schema['type'] !== $value) {
                // integer narrows number; that is agreement, not conflict.
                if (! $this->typesAgree((string) $value, (string) $schema['type'])) {
                    $this->diagnostics->warn(
                        WarningCode::RULE_CONFLICT,
                        sprintf(
                            'Property type resolves to [%s] but validation rules say [%s]. The property type wins.',
                            $value,
                            $schema['type']
                        ),
                        ['component' => $component, 'property' => $key]
                    );
                } elseif ($schema['type'] === 'integer' && $value === 'number') {
                    // Keep the narrower of the two.
                    continue;
                }
            }

            $schema[$keyword] = $value;
        }

        // An items schema from the rules must not overwrite a $ref from the type.
        if (isset($typeFacts['items'])) {
            $schema['items'] = $typeFacts['items'];
        }

        return $schema;
    }

    protected function typesAgree(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // "integer" narrows "number", and Laravel's "array" rule is the correct rule
        // for a PHP array that serialises as a JSON object. Neither is a conflict.
        foreach ([['integer', 'number'], ['array', 'object']] as $group) {
            if (in_array($a, $group, true) && in_array($b, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function makeNullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            // A $ref cannot carry a sibling type keyword, so express nullability as
            // a one-of, which is the draft 2020-12 idiom.
            return ['oneOf' => [['type' => 'null'], ['$ref' => $schema['$ref']]]];
        }

        $type = $schema['type'] ?? null;

        if ($type === null) {
            return $schema;
        }

        $types = (array) $type;

        if (! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        $schema['type'] = $types;

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function confirmationSibling(array $schema, string $key): array
    {
        $sibling = $schema;

        $sibling['x-laravel'] = [
            'property' => null,
            'synthetic' => true,
            'mirrors' => $key,
            'rules' => [],
        ];
        $sibling['x-laravel'] = array_filter(
            $sibling['x-laravel'],
            static fn ($value): bool => $value !== null && $value !== []
        );

        $sibling['x-faker'] = ['strategy' => 'mirror', 'mirrors' => $key];
        $sibling['description'] = sprintf('Must match [%s]. Required by the confirmed rule.', $key);

        return $sibling;
    }

    /**
     * getValidationRules() runs the host's rules() method, which can throw when it
     * depends on the payload or on request state. That is not a reason to fail the
     * whole compile: fall back to the attribute-derived rules alone and say so.
     *
     * @return array<string, mixed>
     */
    protected function validationRules(string $class, string $component): array
    {
        try {
            /** @var array<string, mixed> $rules */
            $rules = $class::getValidationRules([]);

            return $rules;
        } catch (Throwable $exception) {
            $this->diagnostics->warn(
                WarningCode::OPAQUE_RULE,
                sprintf(
                    'getValidationRules() on [%s] threw (%s). Rules declared in rules() are missing from this schema.',
                    $class,
                    $exception->getMessage()
                ),
                ['component' => $component]
            );

            return [];
        }
    }

    /**
     * Pick out the rules belonging to one property, from both sources.
     *
     * Both are needed, and neither is complete. Spatie's getValidationRules() is
     * the only place rules added in a rules() method appear, but when a class
     * defines rules() it *replaces* the attribute-derived set: a property absent
     * from rules() comes back with nothing at all, and its #[Max(500)] is lost.
     * Reading the attributes directly is the only way to get them back.
     *
     * @param array<string, mixed> $rules
     * @return array<int, mixed>
     */
    protected function rulesFor(array $rules, DataProperty $property, string $component): array
    {
        $key = $property->inputMappedName ?? $property->name;

        $declared = (array) ($rules[$key] ?? []);
        $fromAttributes = $this->attributeRules($property);

        $merged = $this->mergeRuleSources($fromAttributes, $declared, $component, $key);

        // "lines" and "lines.*" both constrain the array itself.
        foreach ((array) ($rules[$key.'.*'] ?? []) as $rule) {
            if ($rule === 'distinct') {
                $merged[] = $rule;
            }
        }

        return $merged;
    }

    /**
     * Rules declared as property attributes, read straight off the property.
     *
     * @return array<int, string>
     */
    protected function attributeRules(DataProperty $property): array
    {
        $rules = [];

        foreach ($property->attributes->all(ValidationAttribute::class) as $attribute) {
            try {
                $rendered = (string) $attribute;
            } catch (Throwable) {
                continue;
            }

            foreach (explode('|', $rendered) as $rule) {
                if (($rule = trim($rule)) !== '') {
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }

    /**
     * Attribute-derived facts win on conflict, and the conflict is reported.
     *
     * A disagreement here almost always means a rules() method has drifted from
     * the property signature it is supposed to describe, which is a real bug in the
     * host application and worth surfacing rather than quietly resolving.
     *
     * @param array<int, string> $fromAttributes
     * @param array<int, mixed> $declared
     * @return array<int, mixed>
     */
    protected function mergeRuleSources(array $fromAttributes, array $declared, string $component, string $key): array
    {
        $merged = $fromAttributes;
        $byName = [];

        foreach ($fromAttributes as $rule) {
            $byName[$this->ruleName($rule)] = $rule;
        }

        foreach ($declared as $rule) {
            $name = $this->ruleName($rule);

            if ($name === null) {
                $merged[] = $rule;

                continue;
            }

            if (! isset($byName[$name])) {
                $merged[] = $rule;
                $byName[$name] = $rule;

                continue;
            }

            // Same rule from both sources. Identical is the common case and is not
            // worth a word; different parameters is a genuine disagreement.
            if (is_string($rule) && $byName[$name] !== $rule) {
                $this->diagnostics->warn(
                    WarningCode::RULE_CONFLICT,
                    sprintf(
                        'Attribute declares [%s] but rules() declares [%s]. The attribute wins.',
                        $byName[$name],
                        $rule
                    ),
                    ['component' => $component, 'property' => $key]
                );
            }
        }

        return $merged;
    }

    protected function ruleName(mixed $rule): ?string
    {
        if (! is_string($rule)) {
            return null;
        }

        return strtolower(trim(explode(':', $rule, 2)[0]));
    }

    /**
     * The set of keys a field-referencing rule can legitimately point at.
     *
     * @return array<int, string>
     */
    protected function siblingKeys(DataClass $dataClass): array
    {
        $keys = [];

        /** @var DataProperty $property */
        foreach ($dataClass->properties as $property) {
            $keys[] = $property->inputMappedName ?? $property->name;
            $keys[] = $property->name;
        }

        return array_values(array_unique($keys));
    }

    protected function isDataClass(string $class): bool
    {
        return class_exists($class) && is_a($class, BaseData::class, true);
    }

    protected function normaliseDefault(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value) || $value === null || is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function order(array $schema): array
    {
        if (isset($schema['x-faker']) && is_array($schema['x-faker'])) {
            $schema['x-faker'] = SchemaKeywords::orderFaker($schema['x-faker']);
        }

        return SchemaKeywords::order($schema);
    }

    /**
     * @param array<string, mixed> $laravel
     * @return array<string, mixed>
     */
    protected function orderLaravel(array $laravel): array
    {
        return SchemaKeywords::orderLaravel($laravel);
    }
}
