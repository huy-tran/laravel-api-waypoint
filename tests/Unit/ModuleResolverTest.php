<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\ModuleResolver;
use Modules\Orders\Actions\CreateOrder;
use Nwidart\Modules\Facades\Module;

it('resolves a module from the Modules namespace convention', function (): void {
    expect((new ModuleResolver)->resolve(CreateOrder::class))
        ->toBe(['key' => 'orders', 'name' => 'Orders']);
});

it('snake-cases a multi-word module name for the key and keeps the declared name', function (): void {
    expect((new ModuleResolver)->resolve('Modules\\OrderFulfilment\\Actions\\Ship'))
        ->toBe(['key' => 'order_fulfilment', 'name' => 'OrderFulfilment']);
});

it('falls back to the configured default module', function (): void {
    expect((new ModuleResolver('app'))->resolve('App\\Http\\Controllers\\PingController'))
        ->toBe(['key' => 'app', 'name' => 'App']);

    expect((new ModuleResolver('core'))->resolve('App\\Http\\Controllers\\PingController'))
        ->toBe(['key' => 'core', 'name' => 'Core']);
});

it('falls back for a null or empty class', function (): void {
    $resolver = new ModuleResolver('app');

    expect($resolver->resolve(null))->toBe(['key' => 'app', 'name' => 'App'])
        ->and($resolver->resolve(''))->toBe(['key' => 'app', 'name' => 'App']);
});

it('handles a leading namespace separator', function (): void {
    expect((new ModuleResolver)->resolve('\\Modules\\Billing\\Actions\\RefundOrder'))
        ->toBe(['key' => 'billing', 'name' => 'Billing']);
});

it('does not treat a one-segment namespace as a module', function (): void {
    expect((new ModuleResolver('app'))->resolve('Modules'))
        ->toBe(['key' => 'app', 'name' => 'App']);
});

it('memoises so a resource controller is resolved once, not once per route', function (): void {
    $resolver = new ModuleResolver;

    $first = $resolver->resolve(CreateOrder::class);
    $second = $resolver->resolve(CreateOrder::class);

    expect($second)->toBe($first);
});

it('degrades cleanly when nwidart/laravel-modules is absent', function (): void {
    // The soft dependency is detected with class_exists(), so an app without it
    // still compiles; it just falls through to the namespace convention.
    expect(class_exists(Module::class))->toBeFalse()
        ->and((new ModuleResolver)->resolve(CreateOrder::class))
        ->toBe(['key' => 'orders', 'name' => 'Orders']);
});
