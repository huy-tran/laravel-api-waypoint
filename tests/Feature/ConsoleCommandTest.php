<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Illuminate\Support\Facades\Storage;

function tempPath(string $name): string
{
    return sys_get_temp_dir().'/api-waypoint-tests/'.$name;
}

afterEach(function (): void {
    $directory = sys_get_temp_dir().'/api-waypoint-tests';

    if (is_dir($directory)) {
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('writes the document to a path and creates the directory', function (): void {
    $path = tempPath('nested/schema.json');

    $this->artisan('waypoint:schema', ['--output' => $path, '--pretty' => true])
        ->assertSuccessful();

    expect($path)->toBeReadableFile();

    $document = json_decode((string) file_get_contents($path), true);

    expect($document['schema_format_version'])->toBe('1.0')
        ->and($document['endpoints'])->not->toBeEmpty();

    // Pretty-printed and newline-terminated, so the file diffs cleanly in git.
    expect(file_get_contents($path))->toContain("\n    \"schema_format_version\"")
        ->and(file_get_contents($path))->toEndWith(PHP_EOL);

    array_map('unlink', glob(dirname($path).'/*') ?: []);
    rmdir(dirname($path));
});

it('prints the document to stdout when given no output path', function (): void {
    $this->artisan('waypoint:schema')->assertSuccessful();
});

it('clears the cached document', function (): void {
    $this->artisan('waypoint:schema', ['--clear' => true])
        ->expectsOutputToContain('Cleared')
        ->assertSuccessful();
});

it('reports counts, gaps and warnings', function (): void {
    $this->artisan('waypoint:check')
        ->expectsOutputToContain('7 routes')
        ->expectsOutputToContain('route(s) have no input schema')
        ->expectsOutputToContain('no_data_class')
        ->assertSuccessful();
});

it('exits 0 by default even with gaps, so the check is adoptable', function (): void {
    $this->artisan('waypoint:check')->assertSuccessful();
});

it('exits 1 on unmapped routes when asked to', function (): void {
    $this->artisan('waypoint:check', ['--fail-on-unmapped' => true])->assertFailed();
});

it('exits 1 on warnings when asked to', function (): void {
    $this->artisan('waypoint:check', ['--fail-on-warning' => true])->assertFailed();
});

it('passes a baseline that matches', function (): void {
    $path = tempPath('baseline.json');

    $this->artisan('waypoint:schema', ['--output' => $path])->assertSuccessful();

    $this->artisan('waypoint:check', ['--baseline' => $path])
        ->expectsOutputToContain('Baseline matches')
        ->assertSuccessful();
});

it('fails a baseline whose endpoint hash has moved, and names it', function (): void {
    $path = tempPath('baseline.json');

    $this->artisan('waypoint:schema', ['--output' => $path])->assertSuccessful();

    $document = json_decode((string) file_get_contents($path), true);
    $document['endpoints'][0]['hash'] = 'sha256:000000000000';
    file_put_contents($path, json_encode($document));

    $this->artisan('waypoint:check', ['--baseline' => $path])
        ->expectsOutputToContain('change(s) since the baseline')
        ->expectsOutputToContain('Regenerate with')
        ->assertFailed();
});

it('fails a baseline that names an endpoint no longer present', function (): void {
    $path = tempPath('baseline.json');

    $this->artisan('waypoint:schema', ['--output' => $path])->assertSuccessful();

    $document = json_decode((string) file_get_contents($path), true);
    $document['endpoints'][] = ['id' => 'orders.archive', 'hash' => 'sha256:000000000000'];
    file_put_contents($path, json_encode($document));

    $this->artisan('waypoint:check', ['--baseline' => $path])->assertFailed();
});

it('fails a baseline that does not exist', function (): void {
    $this->artisan('waypoint:check', ['--baseline' => tempPath('missing.json')])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails a baseline that is not valid JSON', function (): void {
    $path = tempPath('broken.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, true);
    }

    file_put_contents($path, 'not json');

    $this->artisan('waypoint:check', ['--baseline' => $path])
        ->expectsOutputToContain('not valid JSON')
        ->assertFailed();
});

it('compiles for CI with the HTTP surface switched off', function (): void {
    // Only route registration is gated on "enabled"; the compiler never is.
    config()->set('api-waypoint.enabled', false);

    $this->artisan('waypoint:check')->assertSuccessful();
    $this->artisan('waypoint:schema')->assertSuccessful();
});

it('reports that no snapshots are stored, and why', function (): void {
    Storage::fake('local');

    $this->artisan('waypoint:snapshot', ['--list' => true])
        ->expectsOutputToContain('No snapshots stored')
        ->expectsOutputToContain('Recording is off')
        ->assertSuccessful();
});

it('lists stored snapshots with their age', function (): void {
    Storage::fake('local');

    app(SnapshotStore::class)->put('orders.show', ['data' => ['id' => 1]], now()->subDays(40)->toIso8601String());

    $this->artisan('waypoint:snapshot', ['--list' => true])
        ->expectsOutputToContain('orders.show')
        ->assertSuccessful();
});

it('prunes stored snapshots', function (): void {
    Storage::fake('local');

    $store = app(SnapshotStore::class);
    $store->put('orders.show', ['data' => ['id' => 1]], now()->toIso8601String());
    $store->put('orders.index', ['data' => []], now()->toIso8601String());

    $this->artisan('waypoint:snapshot', ['--prune' => true])
        ->expectsOutputToContain('Deleted 2 snapshot(s)')
        ->assertSuccessful();

    expect(app(SnapshotStore::class)->list())->toBe([]);
});

it('compiles the document the command writes identically to the compiler', function (): void {
    $path = tempPath('compare.json');

    $this->artisan('waypoint:schema', ['--output' => $path])->assertSuccessful();

    $written = json_decode((string) file_get_contents($path), true);
    $compiled = app(SchemaCompiler::class)->compile()->toArray();

    expect($written['schema_hash'])->toBe($compiled['schema_hash']);
});
