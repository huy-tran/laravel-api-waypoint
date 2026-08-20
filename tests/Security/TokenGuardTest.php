<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Contracts\ResolvesWaypointUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/**
 * A resolver that goes looking for a real customer account. The controller must
 * refuse to mint for it: the email check is not the resolver's to pass or fail.
 */
class ImpersonatingResolver implements ResolvesWaypointUser
{
    public function resolve(string $email, string $role): Authenticatable
    {
        return User::firstOrCreate(
            ['email' => 'real.customer@example.test'],
            ['name' => 'Real Customer', 'password' => 'x']
        );
    }
}

it('mints a token for a declared role', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'admin'])
        ->assertOk();

    expect($response->json('role'))->toBe('admin')
        ->and($response->json('header'))->toBe('Authorization')
        ->and($response->json('value_template'))->toBe('Bearer {token}')
        ->and($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('abilities'))->toBe(['*']);
});

it('mints for a user whose email matches the waypoint pattern', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'admin'])
        ->assertOk();

    // waypoint+{role}@{host}, from APP_URL.
    expect($response->json('user.email'))->toBe('waypoint+admin@acme-orders.test');

    $this->assertDatabaseHas('users', ['email' => 'waypoint+admin@acme-orders.test']);
});

it('rejects a role that is not in config', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'superuser'])
        ->assertStatus(422);

    expect($response->json('code'))->toBe('waypoint.role_not_allowed')
        ->and($response->json('allowed_roles'))->toEqualCanonicalizing(['admin', 'customer']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects a missing role', function (): void {
    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'waypoint.role_not_allowed');
});

it('refuses to mint for a user the resolver picked outside the waypoint pattern', function (): void {
    config()->set('api-waypoint.tokens.roles.rogue', [
        'abilities' => ['*'],
        'resolver' => ImpersonatingResolver::class,
    ]);

    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'rogue'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'waypoint.resolver_returned_foreign_user');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('clamps the requested TTL to the configured maximum', function (): void {
    config()->set('api-waypoint.tokens.max_ttl_minutes', 30);

    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'admin', 'ttl_minutes' => 100000])
        ->assertOk();

    $expiresAt = strtotime((string) $response->json('expires_at'));

    expect($expiresAt)->toBeLessThanOrEqual(time() + (30 * 60) + 5);
});

it('never widens the abilities declared for the role', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', [
            'role' => 'customer',
            'abilities' => ['orders:read', 'billing:refund', '*'],
        ])
        ->assertOk();

    // "customer" declares orders:read only, so that is all it can get.
    expect($response->json('abilities'))->toBe(['orders:read']);
});

it('revokes the previous token for the same role rather than accumulating them', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->withHeaders($this->secretHeader())
            ->postJson('/v1/api-waypoint/tokens', ['role' => 'admin'])
            ->assertOk();
    }

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('404s the token route when token minting is switched off', function (): void {
    config()->set('api-waypoint.tokens.enabled', false);

    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/tokens', ['role' => 'admin'])
        ->assertNotFound();
});
