<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

use Closure;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Illuminate\Validation\Rules\Enum;
use ReflectionClass;
use Stringable;
use Throwable;

/**
 * Normative mapping from Laravel validation rules to JSON Schema keywords.
 *
 * Two passes, because half the table depends on the resolved type: "max:50" is
 * maxLength on a string, maximum on an integer and maxItems on an array. Passing
 * twice is also what makes the mapping order-independent, which matters because
 * rule arrays come from two merged sources and their order is not meaningful.
 *
 * Anything unlisted is preserved verbatim in x-laravel.rules, and anything opaque
 * (a Rule object, a closure) additionally raises an opaque_rule warning, so the
 * document never silently claims to have understood a rule it did not.
 */
class RuleMapper
{
    private const TYPE_STRING = 'string';

    private const TYPE_INTEGER = 'integer';

    private const TYPE_NUMBER = 'number';

    private const TYPE_BOOLEAN = 'boolean';

    private const TYPE_ARRAY = 'array';

    private const TYPE_OBJECT = 'object';

    /** Rules that settle the base type outright. */
    private const TYPE_RULES = [
        'string' => self::TYPE_STRING,
        'integer' => self::TYPE_INTEGER,
        'int' => self::TYPE_INTEGER,
        'numeric' => self::TYPE_NUMBER,
        'decimal' => self::TYPE_NUMBER,
        'boolean' => self::TYPE_BOOLEAN,
        'bool' => self::TYPE_BOOLEAN,
        'array' => self::TYPE_ARRAY,
        'list' => self::TYPE_ARRAY,
        'email' => self::TYPE_STRING,
        'url' => self::TYPE_STRING,
        'active_url' => self::TYPE_STRING,
        'uuid' => self::TYPE_STRING,
        'ulid' => self::TYPE_STRING,
        'ip' => self::TYPE_STRING,
        'ipv4' => self::TYPE_STRING,
        'ipv6' => self::TYPE_STRING,
        'json' => self::TYPE_STRING,
        'timezone' => self::TYPE_STRING,
        'date' => self::TYPE_STRING,
        'date_format' => self::TYPE_STRING,
        'alpha' => self::TYPE_STRING,
        'alpha_num' => self::TYPE_STRING,
        'alpha_dash' => self::TYPE_STRING,
        'regex' => self::TYPE_STRING,
        'starts_with' => self::TYPE_STRING,
        'ends_with' => self::TYPE_STRING,
        'digits' => self::TYPE_STRING,
        'digits_between' => self::TYPE_STRING,
    ];

    /** Rules whose second argument names another field rather than a literal. */
    private const CONDITIONAL_RULES = [
        'required_if', 'required_if_accepted', 'required_if_declined', 'required_unless',
        'required_with', 'required_with_all', 'required_without', 'required_without_all',
        'prohibited_if', 'prohibited_unless', 'prohibits', 'missing_if', 'missing_unless',
        'missing_with', 'missing_with_all', 'exclude_if', 'exclude_unless', 'exclude_with',
        'exclude_without',
    ];

    private const MULTIPART_RULES = ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    /**
     * @param array<int, mixed> $rules
     * @param string|null $typeHint The type derived from the PHP property, used when
     *                              no rule settles it.
     * @param array<int, string> $siblings Sibling property keys, so a field-referencing
     *                                     rule can tell "resolvable" from "not".
     */
    public function map(array $rules, ?string $typeHint = null, array $siblings = []): MappedRules
    {
        $mapped = new MappedRules;

        $normalised = [];
        foreach ($rules as $rule) {
            $normalised = array_merge($normalised, $this->normalise($rule, $mapped));
        }

        $mapped->rules = array_map(
            static fn (array $rule): string => $rule['raw'],
            $normalised
        );

        $mapped->resolvedType = $this->resolveType($normalised, $typeHint);

        if ($mapped->resolvedType !== null) {
            $mapped->schema['type'] = $mapped->resolvedType;
        }

        foreach ($normalised as $rule) {
            $this->apply($rule, $mapped, $siblings);
        }

        // Canonical key order, so a reordered rule array produces a byte-identical
        // schema rather than the same facts in a different sequence.
        $mapped->schema = SchemaKeywords::order($mapped->schema);
        $mapped->laravel = SchemaKeywords::orderLaravel($mapped->laravel);
        $mapped->faker = SchemaKeywords::orderFaker($mapped->faker);

        return $mapped;
    }

    /**
     * Flatten one rule into zero or more {name, parameters, raw} entries.
     *
     * @return array<int, array{name: string, parameters: array<int, string>, raw: string}>
     */
    protected function normalise(mixed $rule, MappedRules $mapped): array
    {
        if (is_string($rule)) {
            // A pipe-delimited string is still legal and appears in rules() methods.
            if (str_contains($rule, '|') && ! str_starts_with($rule, 'regex:') && ! str_starts_with($rule, 'not_regex:')) {
                $flattened = [];
                foreach (explode('|', $rule) as $part) {
                    $flattened = array_merge($flattened, $this->normalise($part, $mapped));
                }

                return $flattened;
            }

            return [$this->parse($rule)];
        }

        if (is_array($rule)) {
            $flattened = [];
            foreach ($rule as $item) {
                $flattened = array_merge($flattened, $this->normalise($item, $mapped));
            }

            return $flattened;
        }

        if ($rule instanceof Closure) {
            $mapped->warn(WarningCode::OPAQUE_RULE, 'A closure rule cannot be described. Pin a value in the Central App.');

            return [['name' => '__closure', 'parameters' => [], 'raw' => 'closure']];
        }

        if (is_object($rule)) {
            return $this->normaliseObject($rule, $mapped);
        }

        return [];
    }

    /**
     * @return array<int, array{name: string, parameters: array<int, string>, raw: string}>
     */
    protected function normaliseObject(object $rule, MappedRules $mapped): array
    {
        // Rule::enum() carries the enum class in a protected property, and its
        // string form does not. Reflection is the only way to keep the class name.
        if ($rule instanceof Enum) {
            $enumClass = $this->readProperty($rule, 'type');

            if (is_string($enumClass) && enum_exists($enumClass)) {
                return [['name' => '__enum', 'parameters' => [$enumClass], 'raw' => 'enum']];
            }
        }

        if ($rule instanceof Stringable || method_exists($rule, '__toString')) {
            $raw = (string) $rule;

            // In/NotIn stringify their values with quotes: in:"draft","paid".
            return [$this->parse($raw)];
        }

        $mapped->warn(
            WarningCode::OPAQUE_RULE,
            sprintf('[%s] is a custom rule object with no string form and cannot be described.', $rule::class)
        );

        return [['name' => '__opaque', 'parameters' => [$rule::class], 'raw' => $rule::class]];
    }

    /**
     * @return array{name: string, parameters: array<int, string>, raw: string}
     */
    protected function parse(string $rule): array
    {
        $rule = trim($rule);

        if (! str_contains($rule, ':')) {
            return ['name' => strtolower($rule), 'parameters' => [], 'raw' => $rule];
        }

        [$name, $arguments] = explode(':', $rule, 2);
        $name = strtolower(trim($name));

        // A regex is a single opaque argument; splitting it on commas destroys it.
        $parameters = in_array($name, ['regex', 'not_regex'], true)
            ? [$arguments]
            : array_map(
                static fn (string $parameter): string => trim(trim($parameter), '"'),
                explode(',', $arguments)
            );

        return ['name' => $name, 'parameters' => $parameters, 'raw' => $rule];
    }

    /**
     * @param array<int, array{name: string, parameters: array<int, string>, raw: string}> $rules
     */
    protected function resolveType(array $rules, ?string $typeHint): ?string
    {
        foreach ($rules as $rule) {
            if (isset(self::TYPE_RULES[$rule['name']])) {
                return self::TYPE_RULES[$rule['name']];
            }
        }

        // "in:" and Rule::enum() imply a string unless the PHP type says otherwise.
        foreach ($rules as $rule) {
            if (in_array($rule['name'], ['in', '__enum', 'accepted'], true)) {
                return $typeHint ?? self::TYPE_STRING;
            }
        }

        return $typeHint;
    }

    /**
     * @param array{name: string, parameters: array<int, string>, raw: string} $rule
     * @param array<int, string> $siblings
     */
    protected function apply(array $rule, MappedRules $mapped, array $siblings): void
    {
        $name = $rule['name'];
        $parameters = $rule['parameters'];
        $type = $mapped->resolvedType;

        match (true) {
            $name === 'required' => $mapped->required = true,
            $name === 'sometimes' => $mapped->optional = true,
            $name === 'present' => $this->applyPresent($mapped),
            $name === 'nullable' => $mapped->nullable = true,
            $name === 'filled' => $mapped->laravel['filled'] = true,

            $name === 'decimal' => $this->applyDecimal($parameters, $mapped),

            $name === 'min' => $this->applyBound($mapped, 'min', $parameters[0] ?? null, $type),
            $name === 'max' => $this->applyBound($mapped, 'max', $parameters[0] ?? null, $type),
            $name === 'between' => $this->applyBetween($mapped, $parameters, $type),
            $name === 'size' => $this->applySize($mapped, $parameters[0] ?? null, $type),

            in_array($name, ['gt', 'gte', 'lt', 'lte'], true) => $this->applyComparison($name, $parameters, $mapped, $siblings),

            $name === 'digits' => $this->applyDigits($mapped, $parameters[0] ?? null),
            $name === 'digits_between' => $this->applyDigitsBetween($mapped, $parameters),

            $name === 'in' => $this->applyIn($mapped, $parameters),
            $name === 'not_in' => $mapped->laravel['not_in'] = $parameters,
            $name === '__enum' => $this->applyEnumRule($mapped, $parameters[0] ?? null),

            $name === 'email' => $mapped->schema['format'] = 'email',
            $name === 'url', $name === 'active_url' => $mapped->schema['format'] = 'uri',
            $name === 'uuid' => $mapped->schema['format'] = 'uuid',
            $name === 'ulid' => $mapped->schema['pattern'] = '^[0-9A-HJKMNP-TV-Z]{26}$',
            $name === 'ip' => $mapped->schema['format'] = 'ip',
            $name === 'ipv4' => $mapped->schema['format'] = 'ipv4',
            $name === 'ipv6' => $mapped->schema['format'] = 'ipv6',
            $name === 'json' => $mapped->laravel['json'] = true,
            $name === 'timezone' => $mapped->faker['strategy'] = 'timezone',

            $name === 'date' => $mapped->schema['format'] = 'date-time',
            $name === 'date_format' => $this->applyDateFormat($mapped, $parameters[0] ?? null),
            in_array($name, ['after', 'after_or_equal', 'before', 'before_or_equal'], true) => $this->applyDateBound($name, $parameters[0] ?? null, $mapped),

            $name === 'regex' => $this->applyRegex($mapped, $parameters[0] ?? ''),
            $name === 'not_regex' => $mapped->laravel['not_regex'] = $parameters[0] ?? '',
            $name === 'starts_with' => $mapped->schema['pattern'] = '^('.$this->alternation($parameters).')',
            $name === 'ends_with' => $mapped->schema['pattern'] = '('.$this->alternation($parameters).')$',
            $name === 'alpha' => $mapped->schema['pattern'] = '^[\\p{L}\\p{M}]+$',
            $name === 'alpha_num' => $mapped->schema['pattern'] = '^[\\p{L}\\p{M}\\p{N}]+$',
            $name === 'alpha_dash' => $mapped->schema['pattern'] = '^[\\p{L}\\p{M}\\p{N}_-]+$',

            $name === 'exists' => $this->applyExists($mapped, $parameters),
            $name === 'unique' => $this->applyUnique($mapped, $parameters),

            $name === 'confirmed' => $mapped->confirmed = true,
            $name === 'same' => $this->applyFieldRule($mapped, 'same', $parameters[0] ?? null, 'mirror', $siblings),
            $name === 'different' => $this->applyFieldRule($mapped, 'different', $parameters[0] ?? null, 'distinct_from', $siblings),

            in_array($name, self::CONDITIONAL_RULES, true) => $this->applyConditional($name, $parameters, $mapped),

            $name === 'distinct' => $mapped->distinct = true,
            $name === 'accepted' => $mapped->schema['enum'] = [true, 'yes', 'on', 1],
            $name === 'declined' => $mapped->schema['enum'] = [false, 'no', 'off', 0],

            in_array($name, self::MULTIPART_RULES, true) => $mapped->multipart = true,

            $name === '__closure', $name === '__opaque' => null,

            // Everything else is preserved verbatim in x-laravel.rules and left alone.
            default => null,
        };
    }

    protected function applyPresent(MappedRules $mapped): void
    {
        $mapped->required = true;
        $mapped->laravel['present_only'] = true;
    }

    /**
     * decimal:s or decimal:min,max constrains the number of decimal places. The
     * tightest thing JSON Schema can say is a multipleOf on the largest scale.
     *
     * @param array<int, string> $parameters
     */
    protected function applyDecimal(array $parameters, MappedRules $mapped): void
    {
        $scale = (int) ($parameters[count($parameters) - 1] ?? 0);

        $mapped->schema['multipleOf'] = $scale > 0 ? (float) ('1e-'.$scale) : 1.0;
        $mapped->laravel['decimal'] = ['places' => $parameters];
        $mapped->faker['strategy'] = 'float';
    }

    protected function applyBound(MappedRules $mapped, string $side, ?string $value, ?string $type): void
    {
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $number = str_contains($value, '.') ? (float) $value : (int) $value;

        $keyword = match ($type) {
            self::TYPE_INTEGER, self::TYPE_NUMBER => $side === 'min' ? 'minimum' : 'maximum',
            self::TYPE_ARRAY => $side === 'min' ? 'minItems' : 'maxItems',
            self::TYPE_OBJECT => $side === 'min' ? 'minProperties' : 'maxProperties',
            // Laravel's default for an untyped value is character count, and every
            // remaining case here is string-shaped.
            default => $side === 'min' ? 'minLength' : 'maxLength',
        };

        $mapped->schema[$keyword] = $number;
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyBetween(MappedRules $mapped, array $parameters, ?string $type): void
    {
        $this->applyBound($mapped, 'min', $parameters[0] ?? null, $type);
        $this->applyBound($mapped, 'max', $parameters[1] ?? null, $type);
    }

    protected function applySize(MappedRules $mapped, ?string $value, ?string $type): void
    {
        $this->applyBound($mapped, 'min', $value, $type);
        $this->applyBound($mapped, 'max', $value, $type);
    }

    /**
     * gt:5 is a bound. gt:other_field is a relationship the Central App has to
     * honour when generating, and cannot honour at all when the referenced name is
     * not a sibling, which is exactly when "unresolvable" earns its keep.
     *
     * @param array<int, string> $parameters
     * @param array<int, string> $siblings
     */
    protected function applyComparison(string $name, array $parameters, MappedRules $mapped, array $siblings): void
    {
        $value = $parameters[0] ?? null;

        if ($value === null) {
            return;
        }

        if (is_numeric($value)) {
            $number = str_contains($value, '.') ? (float) $value : (int) $value;

            $keyword = match ($name) {
                'gt' => 'exclusiveMinimum',
                'gte' => 'minimum',
                'lt' => 'exclusiveMaximum',
                'lte' => 'maximum',
                default => null,
            };

            if ($keyword !== null) {
                $mapped->schema[$keyword] = $number;
            }

            return;
        }

        $this->applyFieldRule($mapped, $name, $value, null, $siblings);
    }

    /**
     * @param array<int, string> $siblings
     */
    protected function applyFieldRule(
        MappedRules $mapped,
        string $rule,
        ?string $field,
        ?string $strategy,
        array $siblings
    ): void {
        if ($field === null) {
            return;
        }

        $resolvable = in_array($field, $siblings, true);

        $mapped->addConditionalRule(array_filter([
            'rule' => $rule,
            'field' => $field,
            'resolvable' => $resolvable,
            'note' => $resolvable
                ? null
                : sprintf(
                    'Bound by [%s], which is not a sibling property. The Central App cannot infer a safe value from the payload alone.',
                    $field
                ),
        ], static fn ($value): bool => $value !== null));

        if (! $resolvable) {
            $mapped->faker = [
                'strategy' => 'unresolvable',
                'reason' => sprintf(
                    'Constrained by [%s], which is not part of this payload. Pin a value, or resolve one via GET /references.',
                    $field
                ),
            ];

            $mapped->warn(
                WarningCode::UNRESOLVABLE_FIELD,
                sprintf('Rule [%s:%s] references [%s], which is not a sibling property.', $rule, $field, $field)
            );

            return;
        }

        if ($strategy !== null) {
            $mapped->faker['strategy'] = $strategy;
            $mapped->faker['mirrors'] = $field;
        }
    }

    protected function applyDigits(MappedRules $mapped, ?string $length): void
    {
        if ($length === null || ! ctype_digit($length)) {
            return;
        }

        $mapped->schema['pattern'] = '^[0-9]{'.$length.'}$';
        $mapped->faker['strategy'] = 'pattern';
        $mapped->faker['pattern'] = str_repeat('#', (int) $length);
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyDigitsBetween(MappedRules $mapped, array $parameters): void
    {
        $min = $parameters[0] ?? null;
        $max = $parameters[1] ?? null;

        if ($min === null || $max === null) {
            return;
        }

        $mapped->schema['pattern'] = '^[0-9]{'.$min.','.$max.'}$';
        $mapped->faker['strategy'] = 'pattern';
        $mapped->faker['pattern'] = str_repeat('#', (int) $min);
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyIn(MappedRules $mapped, array $parameters): void
    {
        $mapped->schema['enum'] = array_map(
            fn (string $value): mixed => $this->castEnumMember($value, $mapped->resolvedType),
            $parameters
        );
    }

    protected function castEnumMember(string $value, ?string $type): mixed
    {
        return match ($type) {
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_NUMBER => (float) $value,
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }

    protected function applyEnumRule(MappedRules $mapped, ?string $enumClass): void
    {
        if ($enumClass === null || ! enum_exists($enumClass)) {
            return;
        }

        $mapped->schema['enum'] = EnumReader::values($enumClass);
        $mapped->laravel['enum_class'] = $enumClass;

        if (($backing = EnumReader::backingType($enumClass)) !== null) {
            $mapped->schema['type'] = $backing === 'int' ? self::TYPE_INTEGER : self::TYPE_STRING;
        }
    }

    protected function applyDateFormat(MappedRules $mapped, ?string $format): void
    {
        if ($format === null) {
            return;
        }

        $mapped->laravel['date_format'] = $format;
        $mapped->faker['strategy'] = 'date';
        $mapped->faker['format'] = $format;

        // A format rule is stricter than "date-time", so it replaces the format
        // keyword rather than sitting next to a claim the value cannot satisfy.
        unset($mapped->schema['format']);
    }

    protected function applyDateBound(string $name, ?string $value, MappedRules $mapped): void
    {
        if ($value === null) {
            return;
        }

        $side = str_starts_with($name, 'after') ? 'after' : 'before';

        $mapped->laravel['date_bounds'][$name] = $value;
        $mapped->faker['strategy'] = 'date';
        $mapped->faker['range'][$side] = $value;
    }

    /**
     * A pattern only makes it into the schema when it is safely convertible to
     * ECMA regex. Emitting a PCRE-only pattern would make the Central App's
     * client-side validation reject payloads the API would accept.
     */
    protected function applyRegex(MappedRules $mapped, string $raw): void
    {
        $pattern = RegexTranslator::toEcma($raw);

        if ($pattern === null) {
            $mapped->faker = [
                'strategy' => 'unresolvable',
                'reason' => 'pcre_only_pattern',
            ];

            $mapped->warn(
                WarningCode::PCRE_ONLY_PATTERN,
                sprintf('Rule [regex:%s] uses PCRE-only constructs and was not converted to a JSON Schema pattern.', $raw)
            );

            return;
        }

        $mapped->schema['pattern'] = $pattern;
    }

    /**
     * @param array<int, string> $values
     */
    protected function alternation(array $values): string
    {
        return implode('|', array_map(
            static fn (string $value): string => preg_quote($value, '/'),
            $values
        ));
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyExists(MappedRules $mapped, array $parameters): void
    {
        [$table, $column] = $this->tableAndColumn($parameters);

        if ($table === null) {
            return;
        }

        $mapped->laravel['exists'] = ['table' => $table, 'column' => $column];
        $mapped->faker['strategy'] = 'reference';
        $mapped->faker['reference'] = ['table' => $table, 'column' => $column];
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyUnique(MappedRules $mapped, array $parameters): void
    {
        [$table, $column] = $this->tableAndColumn($parameters);

        if ($table === null) {
            return;
        }

        $mapped->laravel['unique'] = ['table' => $table, 'column' => $column];
        $mapped->faker['unique'] = true;
    }

    /**
     * exists:connection.table,column and exists:Model::class,column are both legal.
     * A model class is resolved to its table so the reference endpoint can use it.
     *
     * @param array<int, string> $parameters
     * @return array{0: string|null, 1: string}
     */
    protected function tableAndColumn(array $parameters): array
    {
        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? 'id';

        if ($table === null || $table === '') {
            return [null, $column];
        }

        // NULL and the ignore-id forms leak in from unique:table,column,except,idColumn.
        if ($column === '' || strtoupper($column) === 'NULL') {
            $column = 'id';
        }

        if (str_contains($table, '\\') && class_exists($table)) {
            try {
                $model = new $table;

                if (method_exists($model, 'getTable')) {
                    $table = (string) $model->getTable();
                }
            } catch (Throwable) {
                // An un-instantiable model is not worth failing a compile over.
            }
        }

        if (str_contains($table, '.')) {
            $table = substr($table, (int) strrpos($table, '.') + 1);
        }

        return [$table, $column];
    }

    /**
     * @param array<int, string> $parameters
     */
    protected function applyConditional(string $name, array $parameters, MappedRules $mapped): void
    {
        $field = $parameters[0] ?? null;
        $values = array_slice($parameters, 1);

        $mapped->addConditionalRule(array_filter([
            'rule' => $name,
            'field' => $field,
            'values' => $values ?: null,
        ], static fn ($value): bool => $value !== null));

        if ($field === null) {
            return;
        }

        if (str_starts_with($name, 'required')) {
            $mapped->optional = true;
            $mapped->faker['required_when'] = array_filter([
                'field' => $field,
                'in' => $values ?: null,
            ], static fn ($value): bool => $value !== null);

            return;
        }

        if (str_starts_with($name, 'prohibited') || str_starts_with($name, 'missing') || str_starts_with($name, 'exclude')) {
            $mapped->faker['omit_when'] = array_filter([
                'field' => $field,
                'in' => $values ?: null,
            ], static fn ($value): bool => $value !== null);
        }
    }

    protected function readProperty(object $object, string $property): mixed
    {
        try {
            $reflection = new ReflectionClass($object);

            if (! $reflection->hasProperty($property)) {
                return null;
            }

            $reflected = $reflection->getProperty($property);
            $reflected->setAccessible(true);

            return $reflected->getValue($object);
        } catch (Throwable) {
            return null;
        }
    }
}
