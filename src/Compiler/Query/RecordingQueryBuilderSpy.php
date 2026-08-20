<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Query;

use Hygo\ApiWaypoint\Compiler\Data\EnumReader;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionNamedType;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

/**
 * Optional, off by default: discovers a query contract by running the Action's
 * own query() method and reading back the allowed lists it built.
 *
 * THIS EXECUTES HOST CODE. Spatie's allowedFilters()/allowedSorts() only assemble
 * the builder, so no database query runs, but query() itself is the host's method
 * and may do anything. Every endpoint discovered this way is marked
 * x-laravel.query_source = "probe" and raises a probed_query_config warning, so
 * the Central App can show it as lower confidence.
 *
 * The contract resolver is always preferable. This exists so a large existing
 * codebase gets useful output before it has adopted the contract anywhere.
 */
class RecordingQueryBuilderSpy
{
    /**
     * Returns the recorded configuration, or null when nothing could be probed.
     *
     * @param string $actionClass A class name from the route table, not yet
     *                            known to be reflectable.
     * @return array<string, mixed>|null
     */
    public function probe(string $actionClass): ?array
    {
        if (! class_exists(QueryBuilder::class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($actionClass);
        } catch (Throwable) {
            return null;
        }

        if (! $this->hasProbableQueryMethod($reflection)) {
            return null;
        }

        try {
            $action = app($actionClass);
            $builder = $action->query();
        } catch (Throwable) {
            // A query() that needs request state, a bound model or a live connection
            // is simply not probeable. That is a "no", not a compile failure.
            return null;
        }

        if (! $builder instanceof QueryBuilder) {
            return null;
        }

        return [
            'filters' => $this->readFilters($builder),
            'sorts' => $this->readSorts($builder),
            'includes' => $this->readIncludes($builder),
            'fields' => $this->readFields($builder),
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    protected function hasProbableQueryMethod(ReflectionClass $reflection): bool
    {
        if (! $reflection->hasMethod('query')) {
            return false;
        }

        $method = $reflection->getMethod('query');

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $returnType = $method->getReturnType();

        // Insisting on the declared return type keeps the probe away from any
        // method that merely happens to be called query().
        return $returnType instanceof ReflectionNamedType
            && is_a($returnType->getName(), QueryBuilder::class, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readFilters(object $builder): array
    {
        $filters = [];

        foreach ($this->collection($builder, 'allowedFilters') as $filter) {
            $name = $this->read($filter, 'name');

            if (! is_string($name)) {
                continue;
            }

            $inner = $this->read($filter, 'filterClass');

            $filters[] = array_filter([
                'name' => $name,
                'type' => $this->filterType($inner),
                'multiple' => false,
                'class' => $this->customFilterClass($inner),
                'values' => $this->enumValues($filter),
            ], static fn ($value): bool => $value !== null);
        }

        return $filters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readSorts(object $builder): array
    {
        $sorts = [];

        foreach ($this->collection($builder, 'allowedSorts') as $sort) {
            $name = $this->read($sort, 'name');

            if (is_string($name)) {
                $sorts[] = ['name' => $name, 'default' => false];
            }
        }

        return $sorts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readIncludes(object $builder): array
    {
        $includes = [];

        foreach ($this->collection($builder, 'allowedIncludes') as $include) {
            $name = $this->read($include, 'name');

            if (! is_string($name)) {
                continue;
            }

            $inner = $this->read($include, 'includeClass');
            $isCount = $inner !== null && str_contains($inner::class, 'Count');

            $includes[] = [
                'name' => $name,
                'type' => $isCount ? 'count' : 'relationship',
                'count_variant' => $isCount,
            ];
        }

        return $includes;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function readFields(object $builder): array
    {
        $fields = [];

        foreach ($this->collection($builder, 'allowedFields') as $field) {
            if (! is_string($field)) {
                continue;
            }

            [$table, $column] = str_contains($field, '.')
                ? explode('.', $field, 2)
                : ['', $field];

            $fields[$table][] = $column;
        }

        unset($fields['']);
        ksort($fields);

        return $fields;
    }

    protected function filterType(?object $filter): string
    {
        if ($filter === null) {
            return QueryConfig::FILTER_EXACT;
        }

        return match (true) {
            str_contains($filter::class, 'FiltersExact') => QueryConfig::FILTER_EXACT,
            str_contains($filter::class, 'FiltersPartial') => QueryConfig::FILTER_PARTIAL,
            str_contains($filter::class, 'FiltersBeginsWith') => QueryConfig::FILTER_BEGINS_WITH,
            str_contains($filter::class, 'FiltersEndsWith') => QueryConfig::FILTER_ENDS_WITH,
            str_contains($filter::class, 'FiltersScope') => QueryConfig::FILTER_SCOPE,
            str_contains($filter::class, 'FiltersTrashed') => QueryConfig::FILTER_TRASHED,
            str_contains($filter::class, 'FiltersCallback') => QueryConfig::FILTER_CALLBACK,
            default => QueryConfig::FILTER_CUSTOM,
        };
    }

    protected function customFilterClass(?object $filter): ?string
    {
        if ($filter === null) {
            return null;
        }

        return $this->filterType($filter) === QueryConfig::FILTER_CUSTOM ? $filter::class : null;
    }

    /**
     * @return array<int, string|int>|null
     */
    protected function enumValues(object $filter): ?array
    {
        $enum = $this->read($filter, 'enum');

        return is_string($enum) && enum_exists($enum) ? EnumReader::values($enum) : null;
    }

    /**
     * @return iterable<mixed>
     */
    protected function collection(object $builder, string $property): iterable
    {
        $value = $this->read($builder, $property);

        if ($value instanceof Collection) {
            return $value->all();
        }

        return is_array($value) ? $value : [];
    }

    protected function read(object $object, string $property): mixed
    {
        try {
            $reflection = new ReflectionClass($object);

            while (! $reflection->hasProperty($property)) {
                $parent = $reflection->getParentClass();

                if ($parent === false) {
                    return null;
                }

                $reflection = $parent;
            }

            $reflected = $reflection->getProperty($property);
            $reflected->setAccessible(true);

            return $reflected->isInitialized($object) ? $reflected->getValue($object) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
