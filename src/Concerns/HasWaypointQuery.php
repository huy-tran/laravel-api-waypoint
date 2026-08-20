<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Concerns;

use Hygo\ApiWaypoint\Compiler\Query\QueryConfig;
use Hygo\ApiWaypoint\Contracts\ProvidesWaypointQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Applies a QueryConfig to a Spatie QueryBuilder.
 *
 * This is what makes ProvidesWaypointQuery worth adopting. Before:
 *
 *     QueryBuilder::for(Order::class)
 *         ->allowedFilters([
 *             AllowedFilter::exact('status'),
 *             AllowedFilter::partial('reference'),
 *         ])
 *         ->allowedSorts(['placed_at', 'total_cents'])
 *         ->defaultSort('-placed_at')
 *         ->allowedIncludes(['customer', 'lines'])
 *         ->paginate(min($request->integer('per_page', 15), 100));
 *
 * After:
 *
 *     public static function waypointQuery(): QueryConfig
 *     {
 *         return QueryConfig::make()
 *             ->exactFilter('status', values: OrderStatus::class)
 *             ->partialFilter('reference')
 *             ->sorts(['placed_at' => 'desc', 'total_cents'])
 *             ->includes(['customer', 'lines'])
 *             ->pagination(perPage: 15, max: 100);
 *     }
 *
 *     public function handle(): LengthAwarePaginator
 *     {
 *         return $this->waypointPaginate($this->queryBuilder(Order::class));
 *     }
 *
 * One declaration, used by both the endpoint and the schema, so they cannot drift.
 *
 * @phpstan-require-implements ProvidesWaypointQuery
 */
trait HasWaypointQuery
{
    /**
     * @param Builder<Model>|class-string<Model> $subject
     * @return QueryBuilder<Model>
     */
    public function queryBuilder(Builder|string $subject): QueryBuilder
    {
        if (! class_exists(QueryBuilder::class)) {
            throw new RuntimeException(
                'spatie/laravel-query-builder is not installed. Require it, or stop using HasWaypointQuery.'
            );
        }

        $config = static::waypointQuery();

        // Spread rather than pass arrays: query-builder v7 made these variadic, and
        // v6's array form also accepts spread arguments, so this works on both.
        $builder = QueryBuilder::for($subject)
            ->allowedFilters(...$this->waypointAllowedFilters($config))
            ->allowedSorts(...$this->waypointAllowedSorts($config));

        // Fields must be declared before includes. Spatie throws
        // AllowedFieldsMustBeCalledBeforeAllowedIncludes when they are the other way
        // round, because an include needs to know which fields are selectable on the
        // relation it is adding.
        if (($fields = $config->rawFields()) !== []) {
            $builder->allowedFields(...$this->waypointAllowedFields($fields));
        }

        $builder->allowedIncludes(...$this->waypointAllowedIncludes($config));

        if (($default = $config->defaultSort()) !== null) {
            $builder->defaultSort($default);
        }

        return $builder;
    }

    /**
     * Paginate with the declared default and ceiling, so per_page cannot be used
     * to ask the database for a million rows.
     *
     * @param QueryBuilder<Model> $builder
     */
    public function waypointPaginate(QueryBuilder $builder, ?int $perPage = null): mixed
    {
        $config = static::waypointQuery();
        $pagination = $config->rawPagination();

        $requested = $perPage ?? (int) request()->input(
            $pagination['query_keys']['per_page'] ?? 'per_page',
            $pagination['per_page_default']
        );

        $size = max(1, min($requested, (int) $pagination['per_page_max']));

        return ($pagination['style'] ?? 'page') === 'cursor'
            ? $builder->cursorPaginate($size)->withQueryString()
            : $builder->paginate($size)->withQueryString();
    }

    /**
     * @return array<int, AllowedFilter>
     */
    protected function waypointAllowedFilters(QueryConfig $config): array
    {
        $filters = [];

        foreach ($config->rawFilters() as $filter) {
            $name = $filter['name'];
            $column = $filter['column'] ?? null;

            $allowed = match ($filter['type']) {
                QueryConfig::FILTER_EXACT => AllowedFilter::exact($name, $column),
                QueryConfig::FILTER_PARTIAL => AllowedFilter::partial($name, $column),
                QueryConfig::FILTER_BEGINS_WITH => AllowedFilter::beginsWith($name, $column),
                QueryConfig::FILTER_ENDS_WITH => AllowedFilter::endsWith($name, $column),
                QueryConfig::FILTER_SCOPE => AllowedFilter::scope($name, $column),
                QueryConfig::FILTER_TRASHED => AllowedFilter::trashed($name),
                QueryConfig::FILTER_CUSTOM => AllowedFilter::custom($name, new $filter['class'], $column),
                default => AllowedFilter::exact($name, $column),
            };

            if (array_key_exists('default', $filter)) {
                $allowed->default($filter['default']);
            }

            $filters[] = $allowed;
        }

        return $filters;
    }

    /**
     * @return array<int, AllowedSort>
     */
    protected function waypointAllowedSorts(QueryConfig $config): array
    {
        return array_map(
            static fn (array $sort): AllowedSort => AllowedSort::field($sort['name']),
            $config->rawSorts()
        );
    }

    /**
     * @return array<int, AllowedInclude>
     */
    protected function waypointAllowedIncludes(QueryConfig $config): array
    {
        $includes = [];

        foreach ($config->rawIncludes() as $include) {
            $includes[] = AllowedInclude::relationship($include['name']);

            if (($include['count_variant'] ?? false) === true) {
                $includes[] = AllowedInclude::count($include['name'].'Count', $include['name']);
            }
        }

        return $includes;
    }

    /**
     * @param array<string, array<int, string>> $fields
     * @return array<int, string>
     */
    protected function waypointAllowedFields(array $fields): array
    {
        $allowed = [];

        foreach ($fields as $table => $columns) {
            foreach ($columns as $column) {
                $allowed[] = $table.'.'.$column;
            }
        }

        return $allowed;
    }
}
