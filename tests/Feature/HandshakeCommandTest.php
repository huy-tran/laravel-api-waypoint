<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Hygo\ApiWaypoint\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Run the command and decode its JSON payload.
 *
 * @return array<string, mixed>
 */
function handshake(): array
{
    Artisan::call('waypoint:handshake', ['--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

it('is registered', function (): void {
    expect(Artisan::all())->toHaveKey('waypoint:handshake');
});

it('publishes the connection details a companion app needs', function (): void {
    $payload = handshake();

    expect($payload['connection']['base_url'])->toBe('http://acme-orders.test/_api-waypoint')
        ->and($payload['connection']['header'])->toBe(VerifyWaypointSecret::HEADER)
        ->and($payload['connection']['secret'])->toBe(TestCase::SECRET)
        ->and($payload['waypoint']['schema_format_version'])->toBe('1.0')
        ->and($payload['registered'])->toBeTrue()
        ->and($payload['unregistered_reason'])->toBeNull();
});

it('hands over a secret that actually opens the surface', function (): void {
    // The point of the whole command: what it prints must work as-is, so a test
    // that only asserts the string matches config would miss a wrong header name.
    $payload = handshake();

    $this->withHeaders([$payload['connection']['header'] => $payload['connection']['secret']])
        ->getJson($payload['paths']['schema'])
        ->assertOk();
});

it('publishes every path so the companion app hardcodes none of them', function (): void {
    $paths = handshake()['paths'];

    expect($paths)->toBe([
        // The document is served at the prefix root, not at {prefix}/schema, which
        // is the path every new consumer tries first.
        'schema' => '/_api-waypoint',
        'manifest' => '/_api-waypoint/manifest',
        'references' => '/_api-waypoint/references/{table}/{column}',
        'scenarios' => '/_api-waypoint/scenarios',
        'tokens' => '/_api-waypoint/tokens',
    ]);
});

it('follows a customised prefix', function (): void {
    $this->withWaypointConfig(['api-waypoint.prefix' => 'v1/api-waypoint']);

    $payload = handshake();

    expect($payload['connection']['base_url'])->toBe('http://acme-orders.test/v1/api-waypoint')
        ->and($payload['paths']['manifest'])->toBe('/v1/api-waypoint/manifest');
});

it('exits non-zero and names the condition when the surface is disabled', function (): void {
    $this->withWaypointConfig(['api-waypoint.enabled' => false]);

    expect(Artisan::call('waypoint:handshake', ['--json' => true]))->toBe(1);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['registered'])->toBeFalse()
        ->and($payload['unregistered_reason'])->toBe('disabled');
});

it('names a missing secret rather than reporting a working connection', function (): void {
    $this->withWaypointConfig(['api-waypoint.secret' => '']);

    $payload = handshake();

    expect($payload['unregistered_reason'])->toBe('no_secret')
        // Null, not an empty string: a consumer must not be able to send it and
        // then read the 404 as a wrong secret.
        ->and($payload['connection']['secret'])->toBeNull();
});

it('names an environment that is not permitted', function (): void {
    $this->withWaypointConfig(['api-waypoint.environments' => ['local']]);

    expect(handshake()['unregistered_reason'])->toBe('environment_not_permitted');
});

it('tells a human how to fix each unmet condition', function (): void {
    $this->withWaypointConfig(['api-waypoint.secret' => '']);

    $this->artisan('waypoint:handshake')
        ->expectsOutputToContain('API_WAYPOINT_SECRET')
        ->assertFailed();
});

it('refuses to print a secret in production', function (): void {
    $this->app->detectEnvironment(static fn (): string => 'production');

    $this->artisan('waypoint:handshake')
        ->expectsOutputToContain('does not run in production')
        ->assertFailed();

    expect(Artisan::output())->not->toContain(TestCase::SECRET);
});
