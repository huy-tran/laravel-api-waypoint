<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Modules\Orders\Transformers\OrderTransformer;

/**
 * The highest-value test in the suite: one assertion that catches every
 * regression in the pipeline, and a fixture the Central App is built against.
 *
 * Regenerate with:
 *   composer golden
 * or
 *   php artisan waypoint:schema --pretty --output=tests/Fixtures/golden.json
 *
 * Then read the diff. A change here is either a deliberate improvement or a bug,
 * and the diff is where you find out which.
 */
const GOLDEN = __DIR__.'/../Fixtures/golden.json';

/**
 * Drop the fields that legitimately differ between one machine and the next.
 *
 * Everything else must match exactly, including hashes.
 *
 * @param array<string, mixed> $document
 * @return array<string, mixed>
 */
function normaliseForGolden(array $document): array
{
    unset(
        $document['generated_at'],
        $document['diagnostics']['counts']['compile_ms'],
        // The CLI compiles as "local" and the test suite as "testing".
        $document['application']['environment'],
        $document['application']['package_version'],
        $document['application']['laravel_version'],
        $document['application']['git'],
    );

    return $document;
}

it('matches the committed golden document exactly', function (): void {
    expect(GOLDEN)->toBeReadableFile(
        'The golden fixture is missing. Generate it with: composer golden'
    );

    $expected = json_decode((string) file_get_contents(GOLDEN), true, 512, JSON_THROW_ON_ERROR);
    $actual = app(SchemaCompiler::class)->compile()->toArray();

    expect(normaliseForGolden($actual))->toEqual(normaliseForGolden($expected));
});

it('describes the six endpoints the workbench declares, and the unnamed one', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(array_column($document['endpoints'], 'id'))->toBe([
        'app.get.health_ping',
        'orders.attachments.store',
        'orders.index',
        'orders.refund',
        'orders.show',
        'orders.store',
        'reports.export',
    ]);
});

it('attributes each endpoint to the module its action lives in', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(endpoint($document, 'orders.store')['module'])->toBe('orders')
        // RefundOrder lives in Modules\Billing even though its URI is under orders.
        ->and(endpoint($document, 'orders.refund')['module'])->toBe('billing')
        ->and(endpoint($document, 'reports.export')['module'])->toBe('reporting')
        ->and(endpoint($document, 'app.get.health_ping')['module'])->toBe('app');
});

it('counts endpoints per module', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $counts = collect($document['modules'])->pluck('endpoint_count', 'key')->all();

    expect($counts)->toBe([
        'app' => 1,
        'billing' => 1,
        'orders' => 4,
        'reporting' => 1,
    ]);
});

it('emits an unmapped endpoint rather than dropping it', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    // Present in endpoints[] as read-only...
    expect(endpoint($document, 'reports.export'))->not->toBeNull()
        ->and(endpoint($document, 'reports.export')['input'])->toBeNull();

    // ...and simultaneously listed as a gap.
    $unmapped = collect($document['diagnostics']['unmapped_routes'])->keyBy('endpoint_id');

    expect($unmapped)->toHaveKey('reports.export')
        ->and($unmapped['reports.export']['reason'])->toBe(UnmappedReason::NO_DATA_CLASS)
        ->and($unmapped['reports.export']['detail'])->toContain('validate');
});

it('reports each unmapped route with the correct, actionable reason', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $reasons = collect($document['diagnostics']['unmapped_routes'])
        ->pluck('reason', 'endpoint_id')
        ->all();

    expect($reasons)->toBe([
        'app.get.health_ping' => UnmappedReason::CLOSURE_ACTION,
        'orders.attachments.store' => UnmappedReason::MULTIPART,
        'reports.export' => UnmappedReason::NO_DATA_CLASS,
    ]);

    foreach ($document['diagnostics']['unmapped_routes'] as $route) {
        expect($route['detail'])->toBeString()->not->toBeEmpty();
    }
});

it('does not treat a bodyless GET as a gap', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $unmapped = array_column($document['diagnostics']['unmapped_routes'], 'endpoint_id');

    expect($unmapped)->not->toContain('orders.index')
        ->not->toContain('orders.show');
});

it('leaves no component behind for an endpoint it refused to describe', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    // AttachmentData is multipart, so it is never compiled at all.
    expect(array_keys($document['components']['data_objects']))
        ->toBe(['Billing.RefundOrderData', 'Orders.CreateOrderData', 'Orders.OrderLineData']);
});

it('emits only warning codes from the closed vocabulary', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    foreach (warningCodes($document) as $code) {
        expect(WarningCode::all())->toContain($code);
    }
});

it('marks a field bound by state outside the payload as unresolvable', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $amount = component($document, 'Billing.RefundOrderData')['properties']['amount_cents'];

    expect($amount['x-faker']['strategy'])->toBe('unresolvable')
        ->and($amount['x-faker']['reason'])->toContain('order_total_cents')
        ->and($amount['x-laravel']['conditional_rules'][0])->toMatchArray([
            'rule' => 'lte',
            'field' => 'order_total_cents',
            'resolvable' => false,
        ]);
});

it('carries the declared precondition and its scenario', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(endpoint($document, 'orders.refund')['preconditions'])->toBe([
        ['description' => 'Order must be in the paid state', 'scenario' => 'paid_order'],
    ]);
});

it('infers the conventional success statuses', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(endpoint($document, 'orders.store')['response']['success_status'])->toBe(201)
        ->and(endpoint($document, 'orders.index')['response']['success_status'])->toBe(200)
        // Overridden by the attribute on RefundOrder.
        ->and(endpoint($document, 'orders.refund')['response']['success_status'])->toBe(202);
});

it('reads the transformer include lists', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(endpoint($document, 'orders.index')['response'])->toMatchArray([
        'transformer' => OrderTransformer::class,
        'shape' => 'collection',
        'available_includes' => ['customer', 'lines', 'payments', 'lines.product'],
        'default_includes' => ['customer'],
    ]);
});

it('documents only the errors an endpoint can actually produce', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $statuses = static fn (string $id): array => array_column(
        endpoint($document, $id)['response']['errors'],
        'status'
    );

    // A body means a 422 is possible; a query contract means a 400 is.
    expect($statuses('orders.store'))->toContain(422)
        ->and($statuses('orders.index'))->toContain(400)
        ->and($statuses('orders.index'))->not->toContain(422)
        ->and($statuses('orders.refund'))->toContain(409);
});

it('records the auth requirement and scheme from the middleware', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    expect(endpoint($document, 'orders.store')['auth'])->toMatchArray([
        'required' => true,
        'scheme' => 'sanctum_bearer',
        'guard' => 'sanctum',
    ]);

    expect(endpoint($document, 'orders.refund')['auth']['abilities'])->toBe(['refund']);
});

it('compiles well within the performance budget', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    // The workbench is tiny, so this is a smoke test for a pathological
    // regression such as recompiling a shared Data class per referencing endpoint.
    expect($document['diagnostics']['counts']['compile_ms'])->toBeLessThan(1500);
});
