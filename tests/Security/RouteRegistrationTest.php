<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\ApiWaypointServiceProvider;
use Hygo\ApiWaypoint\Exceptions\UnsafeConfigurationException;
use Illuminate\Support\Facades\Route;

/**
 * Registration is conditional, not protected. When the conditions are not met the
 * routes must be absent from the route table entirely, so a probe gets Laravel's
 * own 404 and cannot tell this app from one that never installed the package.
 */
function waypointRouteUris(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(static fn ($route): string => $route->uri())
        ->filter(static fn (string $uri): bool => str_contains($uri, 'api-waypoint'))
        ->values()
        ->all();
}

it('registers its routes when enabled, permitted and given a secret', function (): void {
    expect(waypointRouteUris())->not->toBeEmpty();
});

it('registers no routes at all when disabled', function (): void {
    $this->withWaypointConfig(['api-waypoint.enabled' => false]);

    expect(waypointRouteUris())->toBeEmpty();
});

it('registers no routes when the environment is not in the allow list', function (): void {
    $this->withWaypointConfig(['api-waypoint.environments' => ['local']]);

    expect(waypointRouteUris())->toBeEmpty();
});

it('registers no routes when the secret is empty', function (): void {
    $this->withWaypointConfig(['api-waypoint.secret' => '']);

    expect(waypointRouteUris())->toBeEmpty();
});

it('registers no routes when the secret is only whitespace', function (): void {
    $this->withWaypointConfig(['api-waypoint.secret' => '   ']);

    expect(waypointRouteUris())->toBeEmpty();
});

it('registers no routes when the secret is null', function (): void {
    $this->withWaypointConfig(['api-waypoint.secret' => null]);

    expect(waypointRouteUris())->toBeEmpty();
});

it('404s on a waypoint path when the package is disabled, even with the right secret', function (): void {
    $this->withWaypointConfig(['api-waypoint.enabled' => false]);

    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint')
        ->assertNotFound();
});

it('keeps the compiler working while the HTTP surface is disabled, so CI needs no switch', function (): void {
    $this->withWaypointConfig(['api-waypoint.enabled' => false]);

    expect(waypointRouteUris())->toBeEmpty();

    $this->artisan('waypoint:check')->assertSuccessful();
});

it('throws rather than registering when production is listed as a permitted environment', function (): void {
    config()->set('api-waypoint.environments', ['local', 'production']);

    $provider = new ApiWaypointServiceProvider($this->app);

    expect(static fn () => $provider->boot())
        ->toThrow(UnsafeConfigurationException::class, 'must never contain "production"');
});

it('throws when enabled while the application environment is production', function (): void {
    config()->set('api-waypoint.environments', ['local']);
    config()->set('api-waypoint.enabled', true);
    $this->app->detectEnvironment(static fn (): string => 'production');

    $provider = new ApiWaypointServiceProvider($this->app);

    expect(static fn () => $provider->boot())
        ->toThrow(UnsafeConfigurationException::class, 'immediately');
});
