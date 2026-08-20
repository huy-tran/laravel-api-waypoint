<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Attributes\WaypointFaker;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\Faker\StrategyVocabulary;

function hints(array $config = []): FakerHintResolver
{
    return new FakerHintResolver($config);
}

it('lets a component-specific config override win over everything', function (): void {
    $resolved = hints(['overrides' => [
        'Orders.CreateOrderData.email' => ['strategy' => 'uuid'],
        '*.email' => ['strategy' => 'internet.email'],
    ]])->resolve(
        component: 'Orders.CreateOrderData',
        property: 'email',
        schema: ['type' => 'string', 'format' => 'email'],
        attribute: new WaypointFaker(strategy: 'sentence'),
    );

    expect($resolved['strategy'])->toBe('uuid');
});

it('falls back to a wildcard config override', function (): void {
    $resolved = hints(['overrides' => ['*.email' => ['strategy' => 'internet.email']]])
        ->resolve('Orders.Anything', 'email', ['type' => 'string']);

    expect($resolved['strategy'])->toBe('internet.email');
});

it('lets the attribute win over every inferred hint', function (): void {
    $resolved = hints()->resolve(
        component: 'Orders.CreateOrderData',
        property: 'reference',
        schema: ['type' => 'string', 'enum' => ['a', 'b']],
        attribute: new WaypointFaker(strategy: 'pattern', pattern: 'ORD-######'),
    );

    expect($resolved['strategy'])->toBe('pattern')
        ->and($resolved['pattern'])->toBe('ORD-######');
});

it('uses an exists rule to produce a reference strategy', function (): void {
    $resolved = hints()->resolve(
        component: 'Orders.CreateOrderData',
        property: 'customer_id',
        schema: ['type' => 'string', 'x-laravel' => ['exists' => ['table' => 'customers', 'column' => 'uuid']]],
        ruleHints: ['strategy' => 'reference', 'reference' => ['table' => 'customers', 'column' => 'uuid']],
    );

    expect($resolved['strategy'])->toBe('reference')
        ->and($resolved['reference'])->toBe(['table' => 'customers', 'column' => 'uuid']);
});

it('prefers an enum over a name heuristic', function (): void {
    $resolved = hints()->resolve('Orders.X', 'email', ['type' => 'string', 'enum' => ['a', 'b']]);

    expect($resolved['strategy'])->toBe('enum');
});

it('derives a mask from a simple anchored pattern', function (): void {
    $resolved = hints()->resolve('Orders.X', 'reference', ['type' => 'string', 'pattern' => '^ORD-[0-9]{6}$']);

    expect($resolved['strategy'])->toBe('pattern')
        ->and($resolved['pattern'])->toBe('ORD-######');
});

it('reports unresolvable when a pattern is richer than a mask can express', function (): void {
    $resolved = hints()->resolve('Orders.X', 'weird', ['type' => 'string', 'pattern' => '^(foo|bar)+$']);

    expect($resolved['strategy'])->toBe('unresolvable')
        ->and($resolved)->toHaveKey('reason');
});

it('maps a format onto the vocabulary', function (string $format, string $strategy): void {
    $resolved = hints()->resolve('Orders.X', 'field', ['type' => 'string', 'format' => $format]);

    expect($resolved['strategy'])->toBe($strategy);
})->with([
    ['email', 'internet.email'],
    ['uri', 'url'],
    ['uuid', 'uuid'],
    ['date-time', 'date'],
]);

it('falls back to property name heuristics', function (string $property, string $strategy): void {
    expect(hints()->resolve('Orders.X', $property, ['type' => 'string'])['strategy'])->toBe($strategy);
})->with([
    ['first_name', 'person.firstName'],
    ['last_name', 'person.lastName'],
    ['mobile', 'phone'],
    ['suburb', 'address.city'],
    ['state', 'address.state'],
    ['postcode', 'address.postcode'],
    ['company_name', 'company.name'],
    ['slug', 'slug'],
]);

it('matches a name heuristic on a suffix', function (): void {
    expect(hints()->resolve('Orders.X', 'billing_postcode', ['type' => 'string'])['strategy'])
        ->toBe('address.postcode');
});

it('ships Australian defaults for state and postcode', function (): void {
    $state = hints()->resolve('Orders.X', 'state', ['type' => 'string']);

    expect($state['values'])->toBe(['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA']);
    expect(hints()->resolve('Orders.X', 'postcode', ['type' => 'string'])['pattern'])->toBe('####');
});

it('lets a config name hint override a shipped default', function (): void {
    $resolved = hints(['name_hints' => ['state' => ['strategy' => 'sentence']]])
        ->resolve('Orders.X', 'state', ['type' => 'string']);

    expect($resolved['strategy'])->toBe('sentence');
});

it('falls back to the resolved JSON type', function (string $type, string $strategy): void {
    expect(hints()->resolve('Orders.X', 'zzz_unmatched', ['type' => $type])['strategy'])->toBe($strategy);
})->with([
    ['integer', 'int'],
    ['number', 'float'],
    ['boolean', 'boolean'],
    ['string', 'sentence'],
    ['array', 'collection'],
    ['object', 'key_value_map'],
]);

it('reports unresolvable with a reason when nothing matches at all', function (): void {
    $resolved = hints()->resolve('Orders.X', 'zzz_unmatched', []);

    expect($resolved['strategy'])->toBe('unresolvable')
        ->and($resolved['reason'])->toContain('zzz_unmatched');
});

it('adds an include probability to optional and nullable properties only', function (): void {
    $optional = hints()->resolve('Orders.X', 'notes', ['type' => 'string'], optional: true);
    $nullable = hints()->resolve('Orders.X', 'notes', ['type' => 'string'], nullable: true);
    $required = hints()->resolve('Orders.X', 'notes', ['type' => 'string']);

    expect($optional['include_probability'])->toBe(0.5)
        ->and($nullable['include_probability'])->toBe(0.5)
        ->and($required)->not->toHaveKey('include_probability');
});

it('honours a configured default include probability', function (): void {
    $resolved = hints(['default_include_probability' => 0.2])
        ->resolve('Orders.X', 'notes', ['type' => 'string'], optional: true);

    expect($resolved['include_probability'])->toBe(0.2);
});

it('clamps array counts to the ceiling so a maxItems of 50 does not mean 50', function (): void {
    $resolved = hints()->resolve('Orders.X', 'lines', ['type' => 'array', 'minItems' => 1, 'maxItems' => 50]);

    expect($resolved['count'])->toBe(['min' => 1, 'max' => 3]);
});

it('honours a configured array count ceiling', function (): void {
    $resolved = hints(['array_count_ceiling' => 8])
        ->resolve('Orders.X', 'lines', ['type' => 'array', 'maxItems' => 50]);

    expect($resolved['count'])->toBe(['min' => 0, 'max' => 8]);
});

it('carries a uniqueness flag from a unique rule', function (): void {
    $resolved = hints()->resolve(
        'Orders.X',
        'reference',
        ['type' => 'string', 'x-laravel' => ['unique' => ['table' => 'orders', 'column' => 'reference']]],
    );

    expect($resolved['unique'])->toBeTrue();
});

it('never suggests a value outside a schema bound', function (): void {
    // The "quantity" heuristic suggests 1..5; the schema says 10..20 and wins,
    // because generating outside it guarantees a 422.
    $resolved = hints()->resolve('Orders.X', 'quantity', [
        'type' => 'integer',
        'minimum' => 10,
        'maximum' => 20,
    ]);

    expect($resolved['min'])->toBe(10)
        ->and($resolved['max'])->toBe(20);
});

it('emits only strategies in the closed vocabulary', function (): void {
    $properties = [
        'email', 'first_name', 'postcode', 'quantity', 'notes', 'slug', 'state',
        'company', 'latitude', 'timezone', 'reference', 'zzz_unmatched', 'metadata',
    ];

    foreach ($properties as $property) {
        foreach ([['type' => 'string'], ['type' => 'integer'], ['type' => 'array'], []] as $schema) {
            $strategy = hints()->resolve('Orders.X', $property, $schema)['strategy'];

            expect(StrategyVocabulary::knows($strategy))
                ->toBeTrue("[{$strategy}] for [{$property}] is not in the vocabulary");
        }
    }
});
