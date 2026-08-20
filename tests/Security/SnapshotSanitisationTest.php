<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;

function store(array $overrides = []): SnapshotStore
{
    return SnapshotStore::fromConfig(array_merge((array) config('api-waypoint.snapshots'), $overrides));
}

it('removes every configured key at the top level', function (): void {
    $sanitised = store()->sanitise([
        'password' => 'hunter2',
        'token' => '17|kZ8vQ2mFbN4rT9xL',
        'secret' => 'shhh',
        'authorization' => 'Bearer abc',
        'card' => '4111111111111111',
        'iban' => 'GB33BUKB20201555555555',
        'tfn' => '123456789',
        'abn' => '51824753556',
        'name' => 'Marguerite Okonkwo',
    ]);

    foreach (['password', 'token', 'secret', 'authorization', 'card', 'iban', 'tfn', 'abn'] as $key) {
        expect($sanitised[$key])->toBe('[redacted]');
    }

    expect($sanitised['name'])->toBe('Marguerite Okonkwo');
});

it('removes configured keys at every nesting depth', function (): void {
    $sanitised = store()->sanitise([
        'data' => [
            'customer' => [
                'name' => 'Marguerite Okonkwo',
                'credentials' => [
                    'password' => 'hunter2',
                    'nested' => ['api_token' => 'leak-me', 'deeper' => ['secret' => 'shhh']],
                ],
            ],
        ],
    ]);

    $encoded = json_encode($sanitised);

    expect($encoded)->not->toContain('hunter2')
        ->not->toContain('leak-me')
        ->not->toContain('shhh')
        ->and($encoded)->toContain('Marguerite Okonkwo');
});

it('matches redacted keys as case-insensitive substrings', function (): void {
    $sanitised = store()->sanitise([
        'API_TOKEN' => 'leak-me',
        'customerTfn' => '123456789',
        'card_number' => '4111111111111111',
        'Authorization' => 'Bearer abc',
    ]);

    expect(array_values($sanitised))->each->toBe('[redacted]');
});

it('redacts inside arrays of objects', function (): void {
    $sanitised = store()->sanitise([
        'data' => [
            ['id' => 1, 'password' => 'one'],
            ['id' => 2, 'password' => 'two'],
        ],
    ]);

    expect(json_encode($sanitised))->not->toContain('one')->not->toContain('two');
});

it('truncates long strings so snapshots stay small', function (): void {
    $sanitised = store(['max_string_length' => 20])->sanitise([
        'notes' => str_repeat('a', 500),
    ]);

    expect($sanitised['notes'])->toEndWith('...[truncated]')
        ->and(strlen($sanitised['notes']))->toBeLessThan(50);
});

it('truncates long arrays and says how many were dropped', function (): void {
    $sanitised = store(['max_array_items' => 3])->sanitise([
        'data' => range(1, 40),
    ]);

    expect($sanitised['data'])->toHaveCount(4)
        ->and($sanitised['data'][3])->toBe('...[37 more truncated]');
});

it('stops recursing on a pathologically deep structure', function (): void {
    $deep = 'leaf';

    for ($i = 0; $i < 60; $i++) {
        $deep = ['level' => $deep];
    }

    expect(static fn () => store()->sanitise($deep))->not->toThrow(Throwable::class);
});

it('is not fooled by a redacted key holding a nested structure', function (): void {
    $sanitised = store()->sanitise([
        'token' => ['value' => 'leak-me', 'expires' => '2026-01-01'],
    ]);

    expect($sanitised['token'])->toBe('[redacted]');
});
