<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Compilation runs on every request in local, and is cached anywhere else the
 * package is permitted to run, because nobody is editing code on a staging box
 * between requests.
 */
beforeEach(function (): void {
    Cache::flush();

    $this->app->detectEnvironment(static fn (): string => 'staging');
});

it('caches the compiled document outside local', function (): void {
    $repository = app(SchemaRepository::class);

    expect(Cache::get(SchemaRepository::CACHE_KEY))->toBeNull();

    $document = $repository->document();

    expect(Cache::get(SchemaRepository::CACHE_KEY))->toBeArray()
        ->and(Cache::get(SchemaRepository::CACHE_KEY)['schema_hash'])->toBe($document->schemaHash());
});

it('serves a later request from the cache rather than recompiling', function (): void {
    app(SchemaRepository::class)->document();

    $cached = Cache::get(SchemaRepository::CACHE_KEY);
    $cached['schema_hash'] = 'sha256:cafecafecafe';
    Cache::put(SchemaRepository::CACHE_KEY, $cached);

    // A fresh repository, standing in for the next request.
    expect((new SchemaRepository(app(SchemaCompiler::class)))->document()->schemaHash())
        ->toBe('sha256:cafecafecafe');
});

it('busts the cache on demand', function (): void {
    $repository = app(SchemaRepository::class);
    $repository->document();

    expect(Cache::get(SchemaRepository::CACHE_KEY))->not->toBeNull();

    $repository->clear();

    expect(Cache::get(SchemaRepository::CACHE_KEY))->toBeNull();
});

it('busts the cache from the console', function (): void {
    app(SchemaRepository::class)->document();

    $this->artisan('waypoint:schema', ['--clear' => true])->assertSuccessful();

    expect(Cache::get(SchemaRepository::CACHE_KEY))->toBeNull();
});

it('always recompiles for fresh(), whatever the cache says', function (): void {
    $repository = app(SchemaRepository::class);
    $repository->document();

    $cached = Cache::get(SchemaRepository::CACHE_KEY);
    $cached['schema_hash'] = 'sha256:cafecafecafe';
    Cache::put(SchemaRepository::CACHE_KEY, $cached);

    // waypoint:check must never report against a stale cached document.
    expect($repository->fresh()->schemaHash())->not->toBe('sha256:cafecafecafe');
});

it('uses the configured cache store when one is named', function (): void {
    config()->set('api-waypoint.cache.store', 'array');

    app(SchemaRepository::class)->document();

    expect(Cache::store('array')->get(SchemaRepository::CACHE_KEY))->toBeArray();
});
