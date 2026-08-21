<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\ApiWaypointServiceProvider;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointCheckTool;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointEndpointsTool;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointEndpointTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * @param array<string, mixed> $arguments
 */
function callTool(string $tool, array $arguments = []): Response
{
    /** @var Tool $instance */
    $instance = app($tool);

    return $instance->handle(new Request($arguments));
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function toolJson(string $tool, array $arguments = []): array
{
    $decoded = json_decode(callTool($tool, $arguments)->content()->toArray()['text'], true);

    expect($decoded)->toBeArray();

    return $decoded;
}

it('offers its tools to boost without being asked twice', function (): void {
    $included = (array) config('boost.mcp.tools.include');

    expect($included)->toContain(WaypointCheckTool::class)
        ->and($included)->toContain(WaypointEndpointTool::class)
        ->and($included)->toContain(WaypointEndpointsTool::class)
        // Booting twice must not duplicate an entry.
        ->and(array_values(array_unique($included)))->toBe(array_values($included));
});

it('preserves tools another package already registered', function (): void {
    config()->set('boost.mcp.tools.include', ['App\\Mcp\\SomebodyElsesTool']);

    app()->forgetInstance(ApiWaypointServiceProvider::class);
    (new ApiWaypointServiceProvider(app()))->boot();

    expect((array) config('boost.mcp.tools.include'))
        ->toContain('App\\Mcp\\SomebodyElsesTool')
        ->toContain(WaypointCheckTool::class);
});

it('names its tools stably, since agents key on the name', function (): void {
    expect(app(WaypointCheckTool::class)->name())->toBe('waypoint-check')
        ->and(app(WaypointEndpointTool::class)->name())->toBe('waypoint-endpoint')
        ->and(app(WaypointEndpointsTool::class)->name())->toBe('waypoint-endpoints');
});

it('describes every tool, so an agent can tell them apart', function (): void {
    foreach ([WaypointCheckTool::class, WaypointEndpointTool::class, WaypointEndpointsTool::class] as $tool) {
        expect(app($tool)->description())->toBeString()->not->toBeEmpty();
    }
});

it('lists the endpoints with their contract at a glance', function (): void {
    $result = toolJson(WaypointEndpointsTool::class);

    expect($result['total_endpoints'])->toBe(7)
        ->and($result['returned'])->toBe(7)
        ->and($result['schema_hash'])->toStartWith('sha256:');

    $index = collect($result['endpoints'])->keyBy('id');

    expect($index->has('orders.store'))->toBeTrue()
        ->and($index['orders.store']['input'])->toBe('Orders.CreateOrderData')
        ->and($index['orders.index']['has_query_contract'])->toBeTrue();
});

it('filters the list by module', function (): void {
    $result = toolJson(WaypointEndpointsTool::class, ['module' => 'orders']);

    expect($result['returned'])->toBeGreaterThan(0)
        ->and($result['returned'])->toBeLessThan($result['total_endpoints']);

    foreach ($result['endpoints'] as $endpoint) {
        expect($endpoint['module'])->toBe('orders');
    }
});

it('filters the list by substring, case-insensitively', function (): void {
    $result = toolJson(WaypointEndpointsTool::class, ['search' => 'REFUND']);

    expect($result['returned'])->toBe(1)
        ->and($result['endpoints'][0]['id'])->toBe('orders.refund');
});

it('lists only the gaps when asked, with a remedy on each', function (): void {
    $result = toolJson(WaypointEndpointsTool::class, ['unmapped_only' => true]);

    expect($result['returned'])->toBeGreaterThan(0);

    foreach ($result['endpoints'] as $endpoint) {
        expect($endpoint['unmapped_reason'])->toBeIn(UnmappedReason::all())
            ->and($endpoint['remedy'])->toBeString()->not->toBeEmpty();
    }
});

it('resolves an endpoint input schema and every component it references', function (): void {
    $result = toolJson(WaypointEndpointTool::class, ['id' => 'orders.store']);

    expect($result['endpoint']['id'])->toBe('orders.store')
        ->and($result['endpoint']['method'])->toBe('POST');

    // The create carries a nested collection, so the referenced component's own
    // reference has to come back too, or the payload cannot be built.
    expect($result['components']['data_objects'])->toHaveKey('Orders.CreateOrderData')
        ->and(array_keys($result['components']['data_objects']))->toContain('Orders.OrderLineData');
});

it('returns the error responses an endpoint can produce', function (): void {
    $result = toolJson(WaypointEndpointTool::class, ['id' => 'orders.show']);

    expect($result['components']['responses'] ?? [])->not->toBeEmpty();
});

it('reports an endpoint reason and remedy inline', function (): void {
    $unmapped = collect(toolJson(WaypointCheckTool::class)['unmapped_routes'])->first();

    $result = toolJson(WaypointEndpointTool::class, ['id' => $unmapped['endpoint_id']]);

    expect($result['unmapped']['reason'])->toBe($unmapped['reason'])
        ->and($result['unmapped']['remedy'])->toBe($unmapped['remedy']);
});

it('errors helpfully on an unknown endpoint id', function (): void {
    $response = callTool(WaypointEndpointTool::class, ['id' => 'orders.nope']);

    expect($response->isError())->toBeTrue()
        ->and($response->content()->toArray()['text'])->toContain('waypoint-endpoints');
});

it('reports the gap list with counts and a verdict', function (): void {
    $result = toolJson(WaypointCheckTool::class);

    expect($result['counts']['routes_total'])->toBe(7)
        ->and($result['unmapped_routes'])->not->toBeEmpty()
        ->and($result['verdict'])->toContain('no input schema')
        ->and($result['warnings'])->toBeArray();

    foreach ($result['unmapped_routes'] as $route) {
        expect($route)->toHaveKeys(['endpoint_id', 'method', 'uri', 'reason', 'remedy'])
            ->and($route['reason'])->toBeIn(UnmappedReason::all());
    }
});

it('filters the gap list by reason', function (): void {
    $result = toolJson(WaypointCheckTool::class, ['reason' => UnmappedReason::CLOSURE_ACTION]);

    expect($result['unmapped_routes'])->not->toBeEmpty();

    foreach ($result['unmapped_routes'] as $route) {
        expect($route['reason'])->toBe(UnmappedReason::CLOSURE_ACTION);
    }
});

it('states plainly when a filter matches nothing', function (): void {
    $result = toolJson(WaypointCheckTool::class, ['reason' => 'no_such_reason']);

    expect($result['unmapped_routes'])->toBe([])
        ->and($result['verdict'])->toContain('does not need one');
});

it('omits warnings when asked to', function (): void {
    expect(toolJson(WaypointCheckTool::class, ['warnings' => false]))
        ->not->toHaveKey('warnings');
});

it('groups warnings by code with a count', function (): void {
    $warnings = toolJson(WaypointCheckTool::class)['warnings'];

    expect($warnings)->not->toBeEmpty();

    foreach ($warnings as $code => $group) {
        expect($code)->toBeString()
            ->and($group['count'])->toBeGreaterThan(0)
            ->and($group['entries'])->not->toBeEmpty()
            // Capped so one noisy code cannot crowd out the others.
            ->and(count($group['entries']))->toBeLessThanOrEqual(10);
    }
});

it('works with the http surface switched off', function (): void {
    // The compiler is never gated on enabled; only route registration is. An agent
    // in a checkout that never turned waypoint on still gets answers.
    config()->set('api-waypoint.enabled', false);
    config()->set('api-waypoint.secret', null);

    expect(toolJson(WaypointCheckTool::class)['counts']['routes_total'])->toBe(7)
        ->and(toolJson(WaypointEndpointsTool::class)['returned'])->toBe(7);
});

it('recompiles per call, so it never answers from before an edit', function (): void {
    expect(toolJson(WaypointEndpointsTool::class)['returned'])->toBe(7);

    Route::middleware('api')->prefix('api/v1')->group(function (): void {
        Route::get('freshly-added', fn () => null)->name('api.v1.fresh.index');
    });

    expect(toolJson(WaypointEndpointsTool::class)['returned'])->toBe(8);
});
