<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\ActionResolver;
use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\Query\QueryConfig;
use Hygo\ApiWaypoint\Compiler\Query\QueryConfigExtractor;
use Hygo\ApiWaypoint\Compiler\Query\RecordingQueryBuilderSpy;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Illuminate\Support\Facades\Route;
use Modules\Orders\Actions\CreateOrder;
use Modules\Orders\Actions\ListOrders;
use Modules\Orders\Actions\ShowOrder;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Filters\PlacedBetweenFilter;
use Modules\Orders\Models\Order;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

function collectedRoute(string $uri, string $method, string $action, ?string $name = null): CollectedRoute
{
    $route = Route::match([$method], $uri, $action);

    if ($name !== null) {
        $route->name($name);
    }

    return new CollectedRoute($route, $method, $name ?? 'test', $name !== null);
}

/**
 * @return array{0: QueryConfigExtractor, 1: Diagnostics}
 */
function extractor(bool $probe = false): array
{
    $diagnostics = new Diagnostics;

    return [
        new QueryConfigExtractor(new FakerHintResolver, new RecordingQueryBuilderSpy, $diagnostics, $probe),
        $diagnostics,
    ];
}

it('reads a declared query contract', function (): void {
    [$extract] = extractor();
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');

    expect($query['source'])->toBe('spatie/laravel-query-builder')
        ->and($query['x-laravel']['query_source'])->toBe('contract')
        ->and($query['filters'])->toHaveCount(4)
        ->and($query['sorts'])->toHaveCount(3)
        ->and($query['includes'])->toHaveCount(4)
        ->and($query['pagination']['per_page_max'])->toBe(100);
});

it('describes an enum-valued exact filter with its allowed values', function (): void {
    [$extract] = extractor();
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');
    $status = $query['filters'][0];

    expect($status)->toMatchArray([
        'name' => 'status',
        'query_key' => 'filter[status]',
        'type' => 'exact',
        'multiple' => true,
        'allowed_values' => ['draft', 'awaiting_payment', 'paid', 'cancelled'],
    ])->and($status['x-faker']['strategy'])->toBe('enum')
        ->and($status['x-laravel']['enum_class'])->toBe(OrderStatus::class);
});

it('describes a custom filter with its class and value hint', function (): void {
    [$extract] = extractor();
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');
    $filter = collect($query['filters'])->firstWhere('name', 'placed_between');

    expect($filter['type'])->toBe('custom')
        ->and($filter['class'])->toBe(PlacedBetweenFilter::class)
        ->and($filter['value_hint'])->toBe('date_range_csv')
        ->and($filter['x-faker'])->toBe([
            'strategy' => 'date_range',
            'format' => 'Y-m-d',
            'separator' => ',',
        ]);
});

it('marks the first directional sort as the default', function (): void {
    [$extract] = extractor();
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');

    expect($query['sorts'][0])->toBe([
        'name' => 'placed_at',
        'query_key' => 'sort',
        'default' => true,
        'default_direction' => 'desc',
    ])->and($query['sorts'][1]['default'])->toBeFalse();
});

it('generates a filter value the same way it generates a property value', function (): void {
    [$extract] = extractor();
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');

    // A dotted filter is a relation path; the leaf is what the heuristics see.
    expect(collect($query['filters'])->firstWhere('name', 'customer.name')['x-faker']['strategy'])
        ->toBe('person.lastName');
});

it('returns null and warns for a collection endpoint with no contract', function (): void {
    [$extract, $diagnostics] = extractor();
    $route = collectedRoute('api/v1/things', 'GET', ShowOrder::class, 'api.v1.things.index');

    expect($extract->extract($route, (new ActionResolver)->resolve($route->route), 'things.index'))->toBeNull()
        ->and(array_column($diagnostics->warnings(), 'code'))->toContain(WarningCode::NO_QUERY_CONFIG);
});

it('stays quiet about a single-resource endpoint with no contract', function (): void {
    [$extract, $diagnostics] = extractor();
    $route = collectedRoute('api/v1/orders/{order}', 'GET', ShowOrder::class, 'api.v1.orders.show');

    expect($extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.show'))->toBeNull()
        ->and($diagnostics->warnings())->toBe([]);
});

it('stays quiet about a write endpoint with no contract', function (): void {
    [$extract, $diagnostics] = extractor();
    $route = collectedRoute('api/v1/orders', 'POST', CreateOrder::class, 'api.v1.orders.store');

    expect($extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.store'))->toBeNull()
        ->and($diagnostics->warnings())->toBe([]);
});

it('discovers a config by probe, and marks it lower confidence', function (): void {
    [$extract, $diagnostics] = extractor(probe: true);
    $route = collectedRoute('api/v1/probed', 'GET', ProbeableAction::class, 'api.v1.probed.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'probed.index');

    expect($query['x-laravel']['query_source'])->toBe('probe')
        ->and(collect($query['filters'])->pluck('name')->all())->toEqualCanonicalizing(['status', 'reference'])
        ->and(collect($query['sorts'])->pluck('name')->all())->toContain('total_cents')
        ->and(array_column($diagnostics->warnings(), 'code'))->toContain(WarningCode::PROBED_QUERY_CONFIG);
});

it('does not probe when probing is switched off', function (): void {
    [$extract] = extractor(probe: false);
    $route = collectedRoute('api/v1/probed', 'GET', ProbeableAction::class, 'api.v1.probed.index');

    expect($extract->extract($route, (new ActionResolver)->resolve($route->route), 'probed.index'))->toBeNull();
});

it('prefers the contract over the probe when both are available', function (): void {
    [$extract] = extractor(probe: true);
    $route = collectedRoute('api/v1/orders', 'GET', ListOrders::class, 'api.v1.orders.index');

    $query = $extract->extract($route, (new ActionResolver)->resolve($route->route), 'orders.index');

    expect($query['x-laravel']['query_source'])->toBe('contract');
});

it('rejects a value list that is not an enum', function (): void {
    expect(static fn () => QueryConfig::make()->exactFilter('status', values: stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'which is not an enum');
});

it('accepts a literal value list', function (): void {
    $config = QueryConfig::make()->exactFilter('status', values: ['open', 'closed']);

    expect($config->rawFilters()[0]['values'])->toBe(['open', 'closed']);
});

it('reports the default sort in Spatie\'s own notation', function (): void {
    expect(QueryConfig::make()->sorts(['placed_at' => 'desc'])->defaultSort())->toBe('-placed_at')
        ->and(QueryConfig::make()->sorts(['placed_at' => 'asc'])->defaultSort())->toBe('placed_at')
        ->and(QueryConfig::make()->sorts(['placed_at'])->defaultSort())->toBeNull();
});

it('defaults pagination when none was declared', function (): void {
    expect(QueryConfig::make()->rawPagination())->toMatchArray([
        'style' => 'page',
        'per_page_default' => 15,
        'per_page_max' => 100,
    ]);
});

/**
 * An action with no waypoint contract, only a real query() method: the case the
 * probe exists for.
 */
class ProbeableAction
{
    public function query(): QueryBuilder
    {
        return QueryBuilder::for(Order::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::partial('reference'),
            )
            ->allowedSorts('placed_at', 'total_cents')
            ->allowedIncludes('customer');
    }

    public function __invoke(): array
    {
        return [];
    }
}
