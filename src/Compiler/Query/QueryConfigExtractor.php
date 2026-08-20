<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Query;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Hygo\ApiWaypoint\Contracts\ProvidesWaypointQuery;
use Throwable;

/**
 * Produces the "query" block: what the endpoint accepts in the query string.
 *
 * Two resolvers, contract first. An endpoint with neither gets "query": null, and
 * a collection GET with neither additionally gets a no_query_config warning,
 * because that is where the omission actually costs the developer something.
 */
class QueryConfigExtractor
{
    public const SOURCE_CONTRACT = 'contract';

    public const SOURCE_PROBE = 'probe';

    public function __construct(
        protected FakerHintResolver $fakerHints,
        protected RecordingQueryBuilderSpy $spy,
        protected Diagnostics $diagnostics,
        protected bool $probeEnabled = false,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function extract(CollectedRoute $route, ResolvedAction $action, string $endpointId): ?array
    {
        $class = $action->class;

        if ($class === null) {
            return null;
        }

        if (is_a($class, ProvidesWaypointQuery::class, true)) {
            $described = $this->fromContract($class, $endpointId);

            if ($described !== null) {
                return $described;
            }
        }

        if ($this->probeEnabled) {
            $probed = $this->fromProbe($class, $endpointId);

            if ($probed !== null) {
                return $probed;
            }
        }

        if ($this->looksLikeCollectionEndpoint($route)) {
            $this->diagnostics->warn(
                WarningCode::NO_QUERY_CONFIG,
                'Collection endpoint declares no query contract. Implement ProvidesWaypointQuery so filters, '
                .'sorts and includes appear in the Central App.',
                ['endpoint_id' => $endpointId]
            );
        }

        return null;
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>|null
     */
    protected function fromContract(string $class, string $endpointId): ?array
    {
        try {
            /** @var class-string<ProvidesWaypointQuery> $class */
            $config = $class::waypointQuery();
        } catch (Throwable $exception) {
            $this->diagnostics->warn(
                WarningCode::NO_QUERY_CONFIG,
                sprintf('%s::waypointQuery() threw: %s', $class, $exception->getMessage()),
                ['endpoint_id' => $endpointId]
            );

            return null;
        }

        return $this->describe(
            $config->rawFilters(),
            $config->rawSorts(),
            $config->rawIncludes(),
            $config->rawFields(),
            $config->rawPagination(),
            self::SOURCE_CONTRACT,
        );
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>|null
     */
    protected function fromProbe(string $class, string $endpointId): ?array
    {
        $probed = $this->spy->probe($class);

        if ($probed === null) {
            return null;
        }

        $this->diagnostics->warn(
            WarningCode::PROBED_QUERY_CONFIG,
            'Query configuration was discovered by running the action\'s query() method, not declared through '
            .'ProvidesWaypointQuery. Treat it as lower confidence.',
            ['endpoint_id' => $endpointId]
        );

        return $this->describe(
            $probed['filters'],
            $probed['sorts'],
            $probed['includes'],
            $probed['fields'],
            // A probe cannot see pagination, which lives in the paginate() call.
            null,
            self::SOURCE_PROBE,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @param array<int, array<string, mixed>> $sorts
     * @param array<int, array<string, mixed>> $includes
     * @param array<string, array<int, string>> $fields
     * @param array<string, mixed>|null $pagination
     * @return array<string, mixed>
     */
    protected function describe(
        array $filters,
        array $sorts,
        array $includes,
        array $fields,
        ?array $pagination,
        string $source
    ): array {
        return array_filter([
            'source' => 'spatie/laravel-query-builder',
            'x-laravel' => ['query_source' => $source],
            'filters' => array_map(fn (array $filter): array => $this->describeFilter($filter), $filters),
            'sorts' => array_map(static fn (array $sort): array => array_filter([
                'name' => $sort['name'],
                'query_key' => 'sort',
                'default' => (bool) ($sort['default'] ?? false),
                'default_direction' => $sort['default_direction'] ?? null,
            ], static fn ($value): bool => $value !== null), $sorts),
            'includes' => array_map(static fn (array $include): array => [
                'name' => $include['name'],
                'type' => $include['type'] ?? 'relationship',
                'count_variant' => (bool) ($include['count_variant'] ?? false),
            ], $includes),
            'fields' => $fields ?: null,
            'pagination' => $pagination,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    protected function describeFilter(array $filter): array
    {
        $name = (string) $filter['name'];
        $values = $filter['values'] ?? null;

        $schema = array_filter([
            'type' => 'string',
            'enum' => $values,
        ], static fn ($value): bool => $value !== null);

        $described = array_filter([
            'name' => $name,
            'query_key' => 'filter['.$name.']',
            'type' => $filter['type'],
            'multiple' => (bool) ($filter['multiple'] ?? false),
            'allowed_values' => $values,
            'relation' => $filter['relation'] ?? null,
            'class' => $filter['class'] ?? null,
            'value_hint' => $filter['valueHint'] ?? null,
            'default' => $filter['default'] ?? null,
            'x-laravel' => isset($filter['enum_class']) ? ['enum_class' => $filter['enum_class']] : null,
        ], static fn ($value): bool => $value !== null);

        $described['x-faker'] = $this->filterHint($filter, $name, $schema);

        return $described;
    }

    /**
     * Filter values are generated the same way property values are, so the same
     * heuristics apply: an enum filter picks a case, a "customer.name" filter gets
     * a surname, a custom filter uses its declared value hint.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function filterHint(array $filter, string $name, array $schema): array
    {
        if (isset($filter['valueHint'])) {
            return match ($filter['valueHint']) {
                'date_range_csv' => ['strategy' => 'date_range', 'format' => 'Y-m-d', 'separator' => ','],
                'date' => ['strategy' => 'date', 'format' => 'Y-m-d'],
                'boolean' => ['strategy' => 'boolean'],
                'int' => ['strategy' => 'int'],
                default => ['strategy' => 'unresolvable', 'reason' => (string) $filter['valueHint']],
            };
        }

        if (($filter['type'] ?? null) === QueryConfig::FILTER_TRASHED) {
            return ['strategy' => 'enum'];
        }

        // A dotted filter name is a relation path; the last segment is the field
        // whose name the heuristics should see.
        $leaf = str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name;

        return $this->fakerHints->resolve(
            component: '*',
            property: $leaf,
            schema: $schema,
        );
    }

    /**
     * A GET whose route name ends in .index, or whose URI has no trailing
     * parameter, is the case where a missing query contract actually hurts.
     */
    protected function looksLikeCollectionEndpoint(CollectedRoute $route): bool
    {
        if ($route->method !== 'GET') {
            return false;
        }

        $name = (string) $route->name();

        if ($name !== '' && str_ends_with($name, '.index')) {
            return true;
        }

        return ! str_ends_with(rtrim($route->route->uri(), '/'), '}');
    }
}
