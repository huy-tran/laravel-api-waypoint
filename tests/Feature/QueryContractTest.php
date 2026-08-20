<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Actions\ListOrders;
use Modules\Orders\Models\Order;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;

uses(RefreshDatabase::class);

/**
 * The declared QueryConfig is both the description and the runtime configuration.
 * If these two drift apart the whole contract is worthless, so the trait is
 * exercised against a real database, not just compiled.
 */
beforeEach(function (): void {
    DB::table('customers')->insert([
        ['id' => 1, 'uuid' => (string) str()->uuid(), 'name' => 'Okonkwo', 'email' => 'a@example.test', 'status' => 'active'],
        ['id' => 2, 'uuid' => (string) str()->uuid(), 'name' => 'Bianchi', 'email' => 'b@example.test', 'status' => 'active'],
    ]);

    DB::table('orders')->insert([
        ['uuid' => (string) str()->uuid(), 'customer_id' => 1, 'reference' => 'ORD-000001', 'status' => 'paid', 'channel' => 'web', 'total_cents' => 500, 'placed_at' => '2026-01-01 00:00:00'],
        ['uuid' => (string) str()->uuid(), 'customer_id' => 2, 'reference' => 'ORD-000002', 'status' => 'draft', 'channel' => 'web', 'total_cents' => 900, 'placed_at' => '2026-02-01 00:00:00'],
        ['uuid' => (string) str()->uuid(), 'customer_id' => 1, 'reference' => 'INV-000003', 'status' => 'paid', 'channel' => 'phone', 'total_cents' => 100, 'placed_at' => '2026-03-01 00:00:00'],
    ]);
});

function listOrders(array $query = []): array
{
    request()->replace($query);

    return (new ListOrders)->queryBuilder(Order::class)->get()->pluck('reference')->all();
}

it('applies a declared exact filter', function (): void {
    expect(listOrders(['filter' => ['status' => 'paid']]))
        ->toEqualCanonicalizing(['ORD-000001', 'INV-000003']);
});

it('applies a declared partial filter', function (): void {
    expect(listOrders(['filter' => ['reference' => 'ORD-']]))
        ->toEqualCanonicalizing(['ORD-000001', 'ORD-000002']);
});

it('applies a declared relation filter', function (): void {
    expect(listOrders(['filter' => ['customer.name' => 'Bianchi']]))->toBe(['ORD-000002']);
});

it('applies a declared custom filter', function (): void {
    expect(listOrders(['filter' => ['placed_between' => '2026-01-15,2026-02-15']]))
        ->toBe(['ORD-000002']);
});

it('rejects a filter the contract does not declare', function (): void {
    expect(static fn (): array => listOrders(['filter' => ['channel' => 'web']]))
        ->toThrow(InvalidFilterQuery::class);
});

it('applies a declared sort', function (): void {
    expect(listOrders(['sort' => 'total_cents']))
        ->toBe(['INV-000003', 'ORD-000001', 'ORD-000002']);
});

it('applies the declared default sort when none is requested', function (): void {
    // placed_at desc, per QueryConfig::sorts(['placed_at' => 'desc', ...]).
    expect(listOrders())->toBe(['INV-000003', 'ORD-000002', 'ORD-000001']);
});

it('rejects a sort the contract does not declare', function (): void {
    expect(static fn (): array => listOrders(['sort' => 'channel']))
        ->toThrow(InvalidSortQuery::class);
});

it('applies a declared include', function (): void {
    request()->replace(['include' => 'customer']);

    $order = (new ListOrders)->queryBuilder(Order::class)->first();

    expect($order->relationLoaded('customer'))->toBeTrue();
});

it('rejects an include the contract does not declare', function (): void {
    request()->replace(['include' => 'refunds']);

    expect(static fn () => (new ListOrders)->queryBuilder(Order::class)->get())
        ->toThrow(InvalidIncludeQuery::class);
});

it('clamps per_page to the declared maximum', function (): void {
    request()->replace(['per_page' => 100000]);

    $paginator = (new ListOrders)->waypointPaginate((new ListOrders)->queryBuilder(Order::class));

    expect($paginator->perPage())->toBe(100);
});

it('uses the declared default page size', function (): void {
    request()->replace([]);

    $paginator = (new ListOrders)->waypointPaginate((new ListOrders)->queryBuilder(Order::class));

    expect($paginator->perPage())->toBe(15);
});

it('describes exactly what it enforces', function (): void {
    $document = $this->withHeaders($this->secretHeader())->getJson('/v1/api-waypoint')->json();
    $query = endpoint($document, 'orders.index')['query'];

    $declared = collect($query['filters'])->pluck('name')->all();

    // Every filter the document advertises is one the runtime actually accepts,
    // and the one it does not advertise is one the runtime actually rejects.
    expect($declared)->toEqualCanonicalizing(['status', 'reference', 'customer.name', 'placed_between'])
        ->and($declared)->not->toContain('channel');

    expect(collect($query['sorts'])->pluck('name')->all())
        ->toEqualCanonicalizing(['placed_at', 'total_cents', 'reference']);

    expect($query['pagination']['per_page_max'])->toBe(100);
});
