<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Query;

use BackedEnum;
use Hygo\ApiWaypoint\Compiler\Data\EnumReader;
use Hygo\ApiWaypoint\Concerns\HasWaypointQuery;
use InvalidArgumentException;

/**
 * A declarative description of an endpoint's Spatie Query Builder contract, which
 * is also what builds the query.
 *
 * Spatie's allowed lists are assembled inside a runtime method chain, so nothing
 * can read them by reflection. Declaring them here once and building from the
 * same object means adopting the contract removes code rather than adding it: the
 * Action stops writing allowedFilters(...) and calls queryBuilder() instead.
 *
 * @see HasWaypointQuery
 */
class QueryConfig
{
    public const FILTER_EXACT = 'exact';

    public const FILTER_PARTIAL = 'partial';

    public const FILTER_BEGINS_WITH = 'begins_with';

    public const FILTER_ENDS_WITH = 'ends_with';

    public const FILTER_SCOPE = 'scope';

    public const FILTER_TRASHED = 'trashed';

    public const FILTER_CALLBACK = 'callback';

    public const FILTER_CUSTOM = 'custom';

    /** @var array<int, array<string, mixed>> */
    protected array $filters = [];

    /** @var array<int, array<string, mixed>> */
    protected array $sorts = [];

    /** @var array<int, array<string, mixed>> */
    protected array $includes = [];

    /** @var array<string, array<int, string>> */
    protected array $fields = [];

    /** @var array<string, mixed>|null */
    protected ?array $pagination = null;

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param class-string|array<int, string|int>|null $values An enum class, or a literal
     *                                                         list of accepted values.
     */
    public function exactFilter(
        string $name,
        string|array|null $values = null,
        bool $multiple = false,
        ?string $column = null,
        ?string $relation = null,
        mixed $default = null,
    ): self {
        return $this->filter(self::FILTER_EXACT, $name, compact('values', 'multiple', 'column', 'relation', 'default'));
    }

    public function partialFilter(
        string $name,
        bool $multiple = false,
        ?string $column = null,
        ?string $relation = null,
    ): self {
        return $this->filter(self::FILTER_PARTIAL, $name, compact('multiple', 'column', 'relation'));
    }

    public function beginsWithFilter(string $name, ?string $column = null, ?string $relation = null): self
    {
        return $this->filter(self::FILTER_BEGINS_WITH, $name, compact('column', 'relation'));
    }

    public function endsWithFilter(string $name, ?string $column = null, ?string $relation = null): self
    {
        return $this->filter(self::FILTER_ENDS_WITH, $name, compact('column', 'relation'));
    }

    /**
     * @param class-string|array<int, string|int>|null $values
     */
    public function scopeFilter(string $name, string|array|null $values = null, ?string $valueHint = null): self
    {
        return $this->filter(self::FILTER_SCOPE, $name, compact('values', 'valueHint'));
    }

    public function trashedFilter(string $name = 'trashed'): self
    {
        return $this->filter(self::FILTER_TRASHED, $name, [
            'values' => ['with', 'only', ''],
        ]);
    }

    /**
     * @param class-string $class A Spatie Filter implementation.
     */
    public function customFilter(string $name, string $class, ?string $valueHint = null, mixed $default = null): self
    {
        return $this->filter(self::FILTER_CUSTOM, $name, compact('class', 'valueHint', 'default'));
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function filter(string $type, string $name, array $options): self
    {
        $values = $options['values'] ?? null;

        if (is_string($values)) {
            if (! enum_exists($values)) {
                throw new InvalidArgumentException(
                    "Filter [{$name}] was given [{$values}] as its value list, which is not an enum."
                );
            }

            $options['enum_class'] = $values;
            $values = EnumReader::values($values);
        }

        if (is_array($values)) {
            $values = array_map(
                static fn ($value) => $value instanceof BackedEnum ? $value->value : $value,
                $values
            );
        }

        $options['values'] = $values;

        $this->filters[] = array_filter(
            ['name' => $name, 'type' => $type] + $options,
            static fn ($value): bool => $value !== null && $value !== false
        ) + ['multiple' => (bool) ($options['multiple'] ?? false)];

        return $this;
    }

    /**
     * Accepts a flat list, or a map where a value is the default direction:
     *
     *   ->sorts(['placed_at' => 'desc', 'total_cents', 'reference'])
     *
     * The first entry given a direction becomes the endpoint's default sort.
     *
     * @param array<int|string, string> $sorts
     */
    public function sorts(array $sorts): self
    {
        foreach ($sorts as $key => $value) {
            if (is_int($key)) {
                $this->sorts[] = ['name' => $value, 'default' => false];

                continue;
            }

            $direction = strtolower($value) === 'desc' ? 'desc' : 'asc';

            $this->sorts[] = [
                'name' => $key,
                'default' => ! $this->hasDefaultSort(),
                'default_direction' => $direction,
            ];
        }

        return $this;
    }

    public function sort(string $name, ?string $defaultDirection = null): self
    {
        return $this->sorts($defaultDirection === null ? [$name] : [$name => $defaultDirection]);
    }

    protected function hasDefaultSort(): bool
    {
        foreach ($this->sorts as $sort) {
            if ($sort['default'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $includes
     */
    public function includes(array $includes): self
    {
        foreach ($includes as $include) {
            $this->include($include);
        }

        return $this;
    }

    public function include(string $name, bool $count = false, string $type = 'relationship'): self
    {
        $this->includes[] = ['name' => $name, 'type' => $type, 'count_variant' => $count];

        return $this;
    }

    public function countInclude(string $name): self
    {
        return $this->include($name, count: true);
    }

    /**
     * @param array<string, array<int, string>> $fields
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $table => $columns) {
            $this->fields[$table] = array_values(array_map('strval', $columns));
        }

        return $this;
    }

    public function pagination(int $perPage = 15, int $max = 100, string $style = 'page'): self
    {
        $this->pagination = [
            'style' => $style,
            'query_keys' => ['page' => 'page', 'per_page' => 'per_page'],
            'per_page_default' => $perPage,
            'per_page_max' => $max,
        ];

        return $this;
    }

    public function cursorPagination(int $perPage = 15, int $max = 100): self
    {
        $this->pagination = [
            'style' => 'cursor',
            'query_keys' => ['cursor' => 'cursor', 'per_page' => 'per_page'],
            'per_page_default' => $perPage,
            'per_page_max' => $max,
        ];

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawSorts(): array
    {
        return $this->sorts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawIncludes(): array
    {
        return $this->includes;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rawFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawPagination(): array
    {
        return $this->pagination ?? [
            'style' => 'page',
            'query_keys' => ['page' => 'page', 'per_page' => 'per_page'],
            'per_page_default' => 15,
            'per_page_max' => 100,
        ];
    }

    public function defaultSort(): ?string
    {
        foreach ($this->sorts as $sort) {
            if (($sort['default'] ?? false) === true) {
                return (($sort['default_direction'] ?? 'asc') === 'desc' ? '-' : '').$sort['name'];
            }
        }

        return null;
    }
}
