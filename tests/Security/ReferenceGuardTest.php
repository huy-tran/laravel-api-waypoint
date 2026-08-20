<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('customers')->insert([
        [
            'uuid' => '0192f4b0-11c2-7a44-8bd1-2f9e7c0a1b33',
            'name' => 'Marguerite Okonkwo',
            'email' => 'm.okonkwo@example.test',
            'status' => 'active',
            'password' => 'super-secret-hash',
            'remember_token' => 'do-not-leak-me',
        ],
        [
            'uuid' => '0192f4b1-8d0e-7b19-a3c4-51f7ba9e2d07',
            'name' => 'Daniel Okorie',
            'email' => 'd.okorie@example.test',
            'status' => 'suspended',
            'password' => 'another-secret-hash',
            'remember_token' => 'also-do-not-leak',
        ],
    ]);
});

it('reads a pair the compiled schema declares through an exists rule', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid')
        ->assertOk();

    expect($response->json('table'))->toBe('customers')
        ->and($response->json('column'))->toBe('uuid')
        ->and($response->json('total_available'))->toBe(2)
        ->and($response->json('values.0.value'))->toBe('0192f4b0-11c2-7a44-8bd1-2f9e7c0a1b33')
        ->and($response->json('values.0.label'))->toBe('Marguerite Okonkwo');
});

it('404s for a table that exists in the database but nowhere in the compiled schema', function (): void {
    expect(Schema::hasTable('order_lines'))->toBeTrue();

    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/order_lines/id')
        ->assertNotFound();
});

it('404s for a column that exists on a whitelisted table but is not itself whitelisted', function (): void {
    expect(Schema::hasColumn('customers', 'email'))->toBeTrue();

    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/email')
        ->assertNotFound();
});

it('404s for a table that does not exist at all', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/nope/id')
        ->assertNotFound();
});

it('rejects a where key that is not a column on the target table', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?where[not_a_column]=x')
        ->assertStatus(422);
});

it('binds where values instead of interpolating them', function (): void {
    $injection = "'; DROP TABLE customers; --";

    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?where[status]='.urlencode($injection))
        ->assertOk();

    // The value matched nothing, and, crucially, the table is still there.
    expect($response->json('values'))->toBe([])
        ->and(Schema::hasTable('customers'))->toBeTrue()
        ->and(DB::table('customers')->count())->toBe(2);
});

it('binds the q fragment instead of interpolating it', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?q='.urlencode("%' OR '1'='1"))
        ->assertOk()
        ->assertJsonPath('values', []);

    expect(DB::table('customers')->count())->toBe(2);
});

it('applies a legitimate where constraint', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?where[status]=suspended')
        ->assertOk();

    expect($response->json('constraint'))->toBe(['status' => 'suspended'])
        ->and($response->json('values'))->toHaveCount(1)
        ->and($response->json('values.0.label'))->toBe('Daniel Okorie');
});

it('never returns a redacted column as a value, a label or context', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?label=password')
        ->assertOk();

    $body = $response->getContent();

    expect($body)->not->toContain('super-secret-hash')
        ->and($body)->not->toContain('do-not-leak-me')
        ->and($body)->not->toContain('another-secret-hash');

    // Asking for a redacted label silently falls back rather than obliging.
    expect($response->json('label_column'))->not->toBe('password');

    foreach ($response->json('values') as $value) {
        expect(array_keys($value['context'] ?? []))
            ->not->toContain('password')
            ->not->toContain('remember_token');
    }
});

it('refuses to filter on a redacted column', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?where[password]=super-secret-hash')
        ->assertStatus(422);
});

it('clamps limit to 50 however large the request asks for', function (): void {
    DB::table('customers')->insert(array_map(static fn (int $i): array => [
        'uuid' => sprintf('0192f4b0-11c2-7a44-8bd1-2f9e7c0a%04d', $i),
        'name' => 'Bulk Customer '.$i,
        'email' => "bulk{$i}@example.test",
        'status' => 'active',
    ], range(1, 80)));

    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid?limit=5000')
        ->assertOk();

    expect($response->json('values'))->toHaveCount(50)
        ->and($response->json('truncated'))->toBeTrue();
});

it('reports an empty result as a normal answer with a scenario hint', function (): void {
    config()->set('api-waypoint.references.scenario_hints', ['customers' => 'paid_order']);

    DB::table('customers')->delete();

    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/customers/uuid')
        ->assertOk();

    expect($response->json('total_available'))->toBe(0)
        ->and($response->json('values'))->toBe([])
        ->and($response->json('hint.scenario'))->toBe('paid_order');
});

it('opens up a pair listed in references.extra and nothing else', function (): void {
    // Read per request, so this needs no application rebuild.
    config()->set('api-waypoint.references.extra', [
        ['table' => 'products', 'column' => 'id', 'label' => 'name'],
    ]);

    DB::table('products')->insert(['name' => 'Widget', 'price_cents' => 1200, 'is_active' => true]);

    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/products/id')
        ->assertOk()
        ->assertJsonPath('values.0.label', 'Widget');

    $this->withHeaders($this->secretHeader())
        ->getJson('/v1/api-waypoint/references/products/price_cents')
        ->assertNotFound();
});
