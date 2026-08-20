<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Recording\RecordsWaypointResponses;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('api-waypoint.snapshots.enabled', true);

    Route::middleware(RecordsWaypointResponses::class)
        ->get('api/v1/snapshot-demo', fn (): array => [
            'data' => [
                'id' => 'abc',
                'password' => 'hunter2',
                'notes' => str_repeat('x', 900),
                'lines' => range(1, 10),
            ],
        ])
        ->name('api.v1.snapshot_demo.show');
});

it('records a snapshot of a successful response', function (): void {
    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    $snapshot = app(SnapshotStore::class)->get('snapshot_demo.show');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['endpoint_id'])->toBe('snapshot_demo.show')
        ->and($snapshot['captured_at'])->toBeString()
        ->and($snapshot['hash'])->toStartWith('sha256:');
});

it('sanitises and truncates what it records', function (): void {
    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    $example = app(SnapshotStore::class)->get('snapshot_demo.show')['example'];

    expect($example['data']['password'])->toBe('[redacted]')
        ->and($example['data']['notes'])->toEndWith('...[truncated]')
        ->and(strlen($example['data']['notes']))->toBeLessThan(600)
        ->and($example['data']['lines'])->toHaveCount(4)
        ->and($example['data']['lines'][3])->toBe('...[7 more truncated]');
});

it('records nothing when recording is switched off', function (): void {
    config()->set('api-waypoint.snapshots.enabled', false);

    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    expect(app(SnapshotStore::class)->get('snapshot_demo.show'))->toBeNull();
});

it('does not rewrite a snapshot that is still fresh', function (): void {
    $this->getJson('/api/v1/snapshot-demo')->assertOk();
    $first = app(SnapshotStore::class)->get('snapshot_demo.show')['captured_at'];

    $this->travel(1)->hours();
    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    expect(app(SnapshotStore::class)->get('snapshot_demo.show')['captured_at'])->toBe($first);
});

it('replaces a snapshot older than the TTL', function (): void {
    app(SnapshotStore::class)->put(
        'snapshot_demo.show',
        ['data' => ['id' => 'stale']],
        now()->subDays(40)->toIso8601String(),
    );

    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    expect(app(SnapshotStore::class)->get('snapshot_demo.show')['example']['data']['id'])->toBe('abc');
});

it('records nothing for an error response', function (): void {
    Route::middleware(RecordsWaypointResponses::class)
        ->get('api/v1/snapshot-error', fn () => response()->json(['message' => 'nope'], 422))
        ->name('api.v1.snapshot_error.show');

    $this->getJson('/api/v1/snapshot-error')->assertStatus(422);

    expect(app(SnapshotStore::class)->get('snapshot_error.show'))->toBeNull();
});

it('never lets a recording failure affect the response', function (): void {
    config()->set('api-waypoint.snapshots.disk', 'a-disk-that-does-not-exist');

    $this->getJson('/api/v1/snapshot-demo')->assertOk()->assertJsonPath('data.id', 'abc');
});

it('attaches a recorded snapshot to its endpoint in the document', function (): void {
    $this->getJson('/api/v1/snapshot-demo')->assertOk();

    $document = app(SchemaCompiler::class)->compile()->toArray();
    $snapshot = endpoint($document, 'snapshot_demo.show')['response']['snapshot'];

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['example']['data']['password'])->toBe('[redacted]')
        ->and($snapshot)->toHaveKeys(['captured_at', 'hash', 'example'])
        // The endpoint id is the key it hangs from and is not repeated inside.
        ->and($snapshot)->not->toHaveKey('endpoint_id');

    expect($document['diagnostics']['counts']['endpoints_with_snapshots'])->toBe(1);
});

it('advertises the snapshot capability only when recording is on', function (): void {
    expect(app(SchemaCompiler::class)->compile()->toArray()['capabilities']['response_snapshots'])
        ->toBeTrue();

    config()->set('api-waypoint.snapshots.enabled', false);

    expect(app(SchemaCompiler::class)->compile()->toArray()['capabilities']['response_snapshots'])
        ->toBeFalse();
});
