<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Data\ComponentRegistry;
use Hygo\ApiWaypoint\Compiler\Data\DataSchemaCompiler;
use Hygo\ApiWaypoint\Compiler\Data\RuleMapper;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\ModuleResolver;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Hygo\ApiWaypoint\Tests\Fixtures\Data\CastWideningData;
use Hygo\ApiWaypoint\Tests\Fixtures\Data\CategoryData;
use Hygo\ApiWaypoint\Tests\Fixtures\Data\RegistrationData;
use Modules\Orders\Data\CreateOrderData;
use Modules\Orders\Data\OrderLineData;
use Spatie\LaravelData\Support\DataConfig;

/**
 * @return array{0: DataSchemaCompiler, 1: ComponentRegistry, 2: Diagnostics}
 */
function dataCompiler(array $fakerConfig = []): array
{
    $registry = new ComponentRegistry;
    $diagnostics = new Diagnostics;

    $compiler = new DataSchemaCompiler(
        app(DataConfig::class),
        new RuleMapper,
        new FakerHintResolver($fakerConfig),
        $registry,
        new ModuleResolver('app'),
        $diagnostics,
    );

    return [$compiler, $registry, $diagnostics];
}

it('compiles a nested Data class into its own component and references it', function (): void {
    [$compiler, $registry] = dataCompiler();

    $component = $compiler->compile(CreateOrderData::class);
    $schema = $registry->get($component);

    expect($component)->toBe('Orders.CreateOrderData')
        ->and($schema['properties']['lines']['items'])
        ->toBe(['$ref' => '#/components/data_objects/Orders.OrderLineData'])
        ->and($registry->get('Orders.OrderLineData'))->not->toBeNull();
});

it('describes a DataCollection as an array of refs and records the item class', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));
    $lines = $schema['properties']['lines'];

    expect($lines['type'])->toBe('array')
        ->and($lines['x-laravel']['data_collection_of'])->toBe(OrderLineData::class)
        ->and($lines['x-faker']['strategy'])->toBe('collection');
});

it('describes a backed enum by its backing values, not its case names', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CastWideningData::class));

    expect($schema['properties']['priority']['enum'])->toBe([1, 5, 9])
        ->and($schema['properties']['priority']['type'])->toBe('integer');
});

it('describes a pure enum by its case names', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CastWideningData::class));

    expect($schema['properties']['weekday']['enum'])->toBe(['Monday', 'Tuesday'])
        ->and($schema['properties']['weekday']['type'])->toBe('string');
});

it('describes a date property as the string it accepts, not as the PHP type', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CastWideningData::class));

    expect($schema['properties']['occurredAt'])->toMatchArray([
        'type' => 'string',
        'format' => 'date-time',
    ]);
});

it('warns and assumes a string for a class type it has no cast mapping for', function (): void {
    [$compiler, $registry, $diagnostics] = dataCompiler();

    $schema = $registry->get($compiler->compile(CastWideningData::class));

    expect($schema['properties']['custom']['type'])->toBe(['string', 'null']);

    $codes = array_column($diagnostics->warnings(), 'code');
    expect($codes)->toContain(WarningCode::CAST_INPUT_ASSUMED);
});

it('omits Optional properties from required and flags them', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(OrderLineData::class));

    expect($schema['required'])->not->toContain('unit_price_cents')
        ->and($schema['properties']['unit_price_cents']['x-laravel']['optional'])->toBeTrue();
});

it('turns a nullable type into a two-member union', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['properties']['notes']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['notes']['x-laravel']['nullable'])->toBeTrue();
});

it('excludes Lazy properties, which are output only', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['properties'])->not->toHaveKey('audit_trail')
        ->and($schema['properties'])->not->toHaveKey('auditTrail');
});

it('excludes Computed properties, which are output only', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['properties'])->not->toHaveKey('summary');
});

it('keys properties by their input-mapped name and records the PHP name', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['properties'])->toHaveKey('purchase_order_no')
        ->and($schema['properties'])->not->toHaveKey('purchaseOrderNo')
        ->and($schema['properties']['purchase_order_no']['x-laravel']['property'])->toBe('purchaseOrderNo')
        ->and($schema['properties']['purchase_order_no']['x-laravel']['input_name'])->toBe('purchase_order_no');
});

it('cuts a self-referencing Data class with a ref instead of recursing', function (): void {
    [$compiler, $registry, $diagnostics] = dataCompiler();

    $component = $compiler->compile(CategoryData::class);
    $schema = $registry->get($component);

    expect($schema['properties']['children']['items'])
        ->toBe(['$ref' => '#/components/data_objects/App.CategoryData'])
        ->and($schema['properties']['parent']['oneOf'][1])
        ->toBe(['$ref' => '#/components/data_objects/App.CategoryData']);

    expect(array_column($diagnostics->warnings(), 'code'))
        ->toContain(WarningCode::RECURSIVE_DATA_CLASS);
});

it('compiles a shared Data class once however many times it is referenced', function (): void {
    [$compiler, $registry] = dataCompiler();

    $compiler->compile(CreateOrderData::class);
    $before = $registry->count();

    $compiler->compile(CreateOrderData::class);
    $compiler->compile(OrderLineData::class);

    expect($registry->count())->toBe($before);
});

it('emits the sibling a confirmed rule implies', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(RegistrationData::class));

    expect($schema['properties'])->toHaveKey('password_confirmation')
        ->and($schema['required'])->toContain('password_confirmation')
        ->and($schema['properties']['password_confirmation']['x-faker'])
        ->toBe(['strategy' => 'mirror', 'mirrors' => 'password'])
        ->and($schema['properties']['password_confirmation']['x-laravel']['synthetic'])->toBeTrue();
});

it('describes a string-keyed iterable as an object, not an array', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['properties']['metadata']['type'])->toBe(['object', 'null'])
        ->and($schema['properties']['metadata']['additionalProperties'])->toBe(['type' => 'string']);
});

it('closes the object to properties it did not declare', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['type'])->toBe('object')
        ->and($schema['x-laravel']['class'])->toBe(CreateOrderData::class);
});

it('recovers attribute rules that a rules() method would otherwise hide', function (): void {
    [$compiler, $registry] = dataCompiler();

    $schema = $registry->get($compiler->compile(CreateOrderData::class));

    // "notes" is absent from CreateOrderData::rules(), so Spatie reports no rules
    // for it at all. Its #[Max(500)] has to come from reading the attribute.
    expect($schema['properties']['notes']['maxLength'])->toBe(500)
        ->and($schema['properties']['customer_id']['x-laravel']['exists'])
        ->toBe(['table' => 'customers', 'column' => 'uuid']);
});

it('reports a non-Data class rather than compiling it', function (): void {
    [$compiler, , $diagnostics] = dataCompiler();

    expect($compiler->compile(stdClass::class))->toBeNull()
        ->and(array_column($diagnostics->warnings(), 'code'))
        ->toContain(WarningCode::UNCOMPILABLE_DATA_CLASS);
});

it('produces an identical component on two compiles of the same class', function (): void {
    [$first, $firstRegistry] = dataCompiler();
    [$second, $secondRegistry] = dataCompiler();

    $a = $firstRegistry->get($first->compile(CreateOrderData::class));
    $b = $secondRegistry->get($second->compile(CreateOrderData::class));

    expect($b)->toBe($a);
});
