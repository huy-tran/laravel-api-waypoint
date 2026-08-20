<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Waypoint\Scenarios\PaidOrder;

uses(RefreshDatabase::class);

it('lists only the scenarios the application declared', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/scenarios')
        ->assertOk();

    expect($response->json('scenarios'))->toHaveCount(1)
        ->and($response->json('scenarios.0.name'))->toBe('paid_order')
        ->and($response->json('scenarios.0.description'))->toBe('A paid order with lines, ready to refund.')
        ->and($response->json('scenarios.0.parameters.properties'))->toHaveKeys(['channel', 'line_count']);
});

it('runs a declared scenario and reports what it created', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', [
            'scenario' => 'paid_order',
            'parameters' => ['channel' => 'phone', 'line_count' => 3],
        ])
        ->assertCreated();

    expect($response->json('scenario'))->toBe('paid_order')
        ->and($response->json('created'))->toBe(2)
        ->and($response->json('cleanup_token'))->toStartWith('scn_')
        ->and($response->json('records.1.model'))->toBe(Order::class)
        ->and($response->json('records.1.attributes.status'))->toBe('paid');

    $this->assertDatabaseHas('orders', ['status' => 'paid', 'channel' => 'phone']);
    $this->assertDatabaseCount('order_lines', 3);
});

it('rejects an unknown scenario name and says what is available', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', ['scenario' => 'paid_invoice'])
        ->assertStatus(422);

    expect($response->json('code'))->toBe('waypoint.scenario_unknown')
        ->and($response->json('available'))->toBe(['paid_order']);

    $this->assertDatabaseCount('orders', 0);
});

it('rejects a body that tries to pass a class name instead of a declared name', function (): void {
    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', [
            'scenario' => PaidOrder::class,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'waypoint.scenario_unknown');

    $this->assertDatabaseCount('orders', 0);
});

it('ignores a factory or attribute payload smuggled alongside the name', function (): void {
    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', [
            'scenario' => 'paid_order',
            'factory' => Order::class,
            'attributes' => ['status' => 'cancelled', 'total_cents' => 999999],
            'class' => PaidOrder::class,
        ])
        ->assertCreated();

    // The smuggled attributes reached nothing: the order is still what the
    // scenario builds, not what the request asked for.
    $this->assertDatabaseHas('orders', ['status' => 'paid']);
    $this->assertDatabaseMissing('orders', ['status' => 'cancelled']);
    $this->assertDatabaseMissing('orders', ['total_cents' => 999999]);
});

it('validates parameters against the scenario\'s own declared schema', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', [
            'scenario' => 'paid_order',
            'parameters' => ['channel' => 'carrier_pigeon', 'line_count' => 99],
        ])
        ->assertStatus(422);

    expect($response->json('code'))->toBe('waypoint.scenario_parameters_invalid')
        ->and($response->json('errors'))->toHaveKeys(['channel', 'line_count']);

    $this->assertDatabaseCount('orders', 0);
});

it('rejects a non-object parameters payload', function (): void {
    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', [
            'scenario' => 'paid_order',
            'parameters' => 'not-an-object',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'waypoint.scenario_parameters_invalid');
});

it('undoes a run through its cleanup token, in reverse creation order', function (): void {
    $token = $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', ['scenario' => 'paid_order'])
        ->assertCreated()
        ->json('cleanup_token');

    $this->assertDatabaseCount('orders', 1);

    $this->withHeaders($this->secretHeader())
        ->deleteJson('/v1/api-waypoint/scenarios/'.$token)
        ->assertOk()
        ->assertJsonPath('cleanup_token', $token);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('customers', 0);
});

it('404s an unknown cleanup token', function (): void {
    $this->withHeaders($this->secretHeader())
        ->deleteJson('/v1/api-waypoint/scenarios/scn_nope')
        ->assertNotFound()
        ->assertJsonPath('code', 'waypoint.cleanup_token_unknown');
});

it('records the run against the audit table with the actor fingerprint', function (): void {
    $this->withHeaders($this->secretHeader())
        ->postJson('/v1/api-waypoint/scenarios', ['scenario' => 'paid_order'])
        ->assertCreated();

    $run = DB::table('api_waypoint_scenario_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->scenario)->toBe('paid_order')
        // A fingerprint of the secret, never the secret itself.
        ->and($run->actor)->toHaveLength(8)
        ->and($run->actor)->not->toContain('workbench');
});
