<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Illuminate\Support\Facades\Storage;

function compileTwice(): array
{
    return [
        app(SchemaCompiler::class)->compile(),
        app(SchemaCompiler::class)->compile(),
    ];
}

it('produces identical document, endpoint and component hashes on two compiles', function (): void {
    [$first, $second] = compileTwice();

    expect($second->schemaHash())->toBe($first->schemaHash());

    $firstEndpoints = collect($first->endpoints())->pluck('hash', 'id')->all();
    $secondEndpoints = collect($second->endpoints())->pluck('hash', 'id')->all();

    expect($secondEndpoints)->toBe($firstEndpoints)->not->toBeEmpty();

    foreach ($first->dataObjects() as $name => $component) {
        expect($second->dataObjects()[$name]['x-laravel']['hash'])
            ->toBe($component['x-laravel']['hash']);
    }
});

it('produces an identical document body, not just identical hashes', function (): void {
    [$first, $second] = compileTwice();

    $strip = static function (array $document): array {
        unset($document['generated_at'], $document['diagnostics']['counts']['compile_ms']);

        return $document;
    };

    expect($strip($second->toArray()))->toBe($strip($first->toArray()));
});

it('keeps hashes stable when a snapshot exists', function (): void {
    Storage::fake('local');

    $before = app(SchemaCompiler::class)->compile();

    app(SnapshotStore::class)->put(
        'orders.show',
        ['data' => ['id' => 'abc', 'reference' => 'ORD-000001']],
        now()->toIso8601String(),
    );

    $after = app(SchemaCompiler::class)->compile();

    // The snapshot must appear...
    expect($after->toArray()['endpoints'])->not->toBe($before->toArray()['endpoints']);

    $snapshot = collect($after->endpoints())->firstWhere('id', 'orders.show')['response']['snapshot'];
    expect($snapshot)->not->toBeNull();

    // ...without moving a single hash, or the Central App reports drift every time
    // somebody exercises an endpoint.
    expect($after->schemaHash())->toBe($before->schemaHash());

    foreach ($before->endpoints() as $endpoint) {
        expect(collect($after->endpoints())->firstWhere('id', $endpoint['id'])['hash'])
            ->toBe($endpoint['hash']);
    }
});

it('moves the component hash and only the referencing endpoints when a property changes', function (): void {
    $before = app(SchemaCompiler::class)->compile();

    // Simulate the one-property change without editing the fixture on disk: take
    // the real document and alter the component the way an added rule would.
    $document = $before->toArray();
    $document['components']['data_objects']['Orders.OrderLineData']['properties']['quantity']['maximum'] = 500;

    $after = rehash($document);

    $movedComponents = changedKeys(
        componentHashes($before->toArray()),
        componentHashes($after->toArray()),
    );

    $movedEndpoints = changedKeys(
        endpointHashes($before->toArray()),
        endpointHashes($after->toArray()),
    );

    // OrderLineData changed, and CreateOrderData contains it, so a CreateOrderData
    // payload genuinely has a different shape now. Both must move. Billing's
    // RefundOrderData, which references neither, must not.
    expect($movedComponents)->toBe(['Orders.CreateOrderData', 'Orders.OrderLineData']);

    // And exactly the one endpoint that sends that payload. Not orders.index,
    // which sends no body, and not orders.refund, which sends a different one.
    expect($movedEndpoints)->toBe(['orders.store']);
});

it('leaves every hash alone when only a snapshot changes', function (): void {
    $before = app(SchemaCompiler::class)->compile();

    $document = $before->toArray();

    foreach ($document['endpoints'] as $index => $endpoint) {
        $document['endpoints'][$index]['response']['snapshot'] = [
            'captured_at' => now()->toIso8601String(),
            'hash' => 'sha256:abcdef123456',
            'example' => ['data' => ['id' => 1]],
        ];
    }

    $after = rehash($document);

    expect($after->schemaHash())->toBe($before->schemaHash())
        ->and(endpointHashes($after->toArray()))->toBe(endpointHashes($before->toArray()));
});

it('changes the document hash when an endpoint is added', function (): void {
    $before = app(SchemaCompiler::class)->compile();

    $document = $before->toArray();
    $document['endpoints'][] = array_merge($document['endpoints'][0], ['id' => 'orders.archive']);

    expect(rehash($document)->schemaHash())->not->toBe($before->schemaHash());
});

/**
 * Recompute every hash over a hand-modified document, the way finalise() would.
 */
function rehash(array $document): SchemaDocument
{
    return SchemaDocument::fromArray($document)->finalise();
}

function componentHashes(array $document): array
{
    return array_map(
        static fn (array $component): string => $component['x-laravel']['hash'],
        $document['components']['data_objects'],
    );
}

function endpointHashes(array $document): array
{
    return collect($document['endpoints'])->pluck('hash', 'id')->all();
}

function changedKeys(array $before, array $after): array
{
    $changed = [];

    foreach ($after as $key => $value) {
        if (($before[$key] ?? null) !== $value) {
            $changed[] = $key;
        }
    }

    sort($changed);

    return $changed;
}
