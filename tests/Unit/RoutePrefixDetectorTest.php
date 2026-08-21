<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Install\RoutePrefixDetector;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

function detector(): RoutePrefixDetector
{
    return app(RoutePrefixDetector::class);
}

/**
 * @param array<int, array{pattern: string, routes: int}> $candidates
 * @return array<int, string>
 */
function patterns(array $candidates): array
{
    return array_column($candidates, 'pattern');
}

it('finds the workbench api prefix', function (): void {
    $candidates = detector()->candidates();

    expect(patterns($candidates))->toContain('api/*');

    $api = collect($candidates)->firstWhere('pattern', 'api/*');

    // The workbench registers seven endpoints, all under api/v1.
    expect($api['routes'])->toBeGreaterThanOrEqual(5);
});

it('never nominates the prefix the package serves itself from', function (): void {
    // Pinned to a versioned prefix on purpose. The shipped default is unversioned
    // and so could never be nominated whether the guard exists or not; under
    // v1/api-waypoint the seven registered routes would propose v1/*, and waypoint
    // would describe nothing but itself.
    $this->withWaypointConfig(['api-waypoint.prefix' => 'v1/api-waypoint']);

    expect(patterns(detector()->candidates()))->not->toContain('v1/*');
});

it('does not nominate its own unversioned default either', function (): void {
    expect(patterns(detector()->candidates()))->not->toContain('_api-waypoint/*');
});

it('ignores framework and tooling prefixes', function (): void {
    Route::get('livewire/message/{name}', fn () => null);
    Route::get('_debugbar/open', fn () => null);
    Route::get('up', fn () => null);

    expect(patterns(detector()->candidates()))
        ->not->toContain('livewire/*')
        ->not->toContain('_debugbar/*')
        ->not->toContain('up/*');
});

it('ignores a prefix that is neither api nor a version', function (): void {
    Route::get('admin/orders', fn () => null);

    expect(patterns(detector()->candidates()))->not->toContain('admin/*');
});

it('proposes api/* for an application that routes under api', function (): void {
    expect(detector()->propose())->toBe(['api/*']);
});

it('proposes the unprefixed version when it carries more routes', function (): void {
    // A per-file or per-module registrar: Route::prefix('v1') with no api/ ahead of
    // it. Eight beats the workbench's seven.
    foreach (range(1, 8) as $index) {
        Route::get("v1/things/{$index}", fn () => null);
    }

    expect(detector()->propose())->toBe(['v1/*']);
});

it('proposes every version an unprefixed application serves', function (): void {
    foreach (range(1, 8) as $index) {
        Route::get("v1/things/{$index}", fn () => null);
    }

    Route::get('v2/things', fn () => null);

    // v2 is quieter than the workbench's api/*, but it is the same API at a second
    // version: describing only v1 would silently drop half the application.
    expect(detector()->propose())->toBe(['v1/*', 'v2/*']);
});

it('orders candidates by how many routes they match', function (): void {
    foreach (range(1, 20) as $index) {
        Route::get("v3/things/{$index}", fn () => null);
    }

    expect(patterns(detector()->candidates())[0])->toBe('v3/*');
});

it('counts what a set of patterns would collect', function (): void {
    Route::get('v4/things', fn () => null);
    Route::get('v4/things/{thing}', fn () => null);

    expect(detector()->matches(['v4/*']))->toBe(2)
        ->and(detector()->matches([]))->toBe(0);
});

it('excludes the package own routes from the match count', function (): void {
    // Same pinned prefix, for the count rather than the proposal: v1/* would
    // otherwise sweep up the package's own seven routes and report a number that
    // counts waypoint describing itself.
    $this->withWaypointConfig(['api-waypoint.prefix' => 'v1/api-waypoint']);

    expect(detector()->matches(['v1/*']))->toBe(0);
});

it('returns nothing to propose when no route looks like an api', function (): void {
    Route::getRoutes()->refreshNameLookups();

    $detector = new RoutePrefixDetector(new Router(app('events'), app()));

    expect($detector->candidates())->toBe([])
        ->and($detector->propose())->toBe([]);
});
