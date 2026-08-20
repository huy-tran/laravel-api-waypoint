<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Support\CanonicalHasher;

it('produces the documented hash shape', function (): void {
    expect(CanonicalHasher::hash(['a' => 1]))->toMatch('/^sha256:[0-9a-f]{12}$/');
});

it('ignores object key order', function (): void {
    $a = ['type' => 'string', 'maxLength' => 50, 'x-laravel' => ['rules' => ['a', 'b'], 'property' => 'x']];
    $b = ['x-laravel' => ['property' => 'x', 'rules' => ['a', 'b']], 'maxLength' => 50, 'type' => 'string'];

    expect(CanonicalHasher::hash($b))->toBe(CanonicalHasher::hash($a));
});

it('respects list order, because enum and required order are meaningful', function (): void {
    expect(CanonicalHasher::hash(['enum' => ['a', 'b']]))
        ->not->toBe(CanonicalHasher::hash(['enum' => ['b', 'a']]));
});

it('changes when any value changes', function (): void {
    expect(CanonicalHasher::hash(['maxLength' => 50]))
        ->not->toBe(CanonicalHasher::hash(['maxLength' => 51]));
});

it('distinguishes a missing key from a null one', function (): void {
    expect(CanonicalHasher::hash(['a' => 1]))
        ->not->toBe(CanonicalHasher::hash(['a' => 1, 'b' => null]));
});

it('does not escape slashes or unicode', function (): void {
    expect(CanonicalHasher::canonicalize(['class' => 'Modules\\Orders\\Data', 'uri' => '/api/v1', 'name' => 'Ökonkwo']))
        ->toContain('/api/v1')
        ->toContain('Ökonkwo');
});

it('emits no whitespace', function (): void {
    expect(CanonicalHasher::canonicalize(['a' => 1, 'b' => ['c' => 2]]))
        ->toBe('{"a":1,"b":{"c":2}}');
});

it('strips a bare key name at any depth', function (): void {
    $stripped = CanonicalHasher::without([
        'hash' => 'sha256:aaa',
        'nested' => ['hash' => 'sha256:bbb', 'keep' => 1],
        'list' => [['hash' => 'sha256:ccc', 'keep' => 2]],
    ], ['hash']);

    expect($stripped)->toBe([
        'nested' => ['keep' => 1],
        'list' => [['keep' => 2]],
    ]);
});

it('strips a rooted path without touching the same key elsewhere', function (): void {
    $stripped = CanonicalHasher::without([
        'generated_at' => 'now',
        'endpoints' => [['generated_at' => 'keep me']],
    ], ['generated_at']);

    expect($stripped)->toBe(['endpoints' => [[]]]);
});

it('supports a wildcard segment in a rooted path', function (): void {
    $stripped = CanonicalHasher::without([
        'endpoints' => [
            ['id' => 'a', 'response' => ['snapshot' => 'x', 'status' => 200]],
            ['id' => 'b', 'response' => ['snapshot' => 'y', 'status' => 201]],
        ],
    ], ['endpoints.*.response.snapshot']);

    expect($stripped['endpoints'][0]['response'])->toBe(['status' => 200])
        ->and($stripped['endpoints'][1]['response'])->toBe(['status' => 201]);
});

it('hashes floats without losing a zero fraction', function (): void {
    expect(CanonicalHasher::canonicalize(['multipleOf' => 1.0]))->toBe('{"multipleOf":1.0}');
});

it('is stable across two hashes of an equivalent structure built differently', function (): void {
    $first = [];
    $first['b'] = 2;
    $first['a'] = 1;

    $second = ['a' => 1, 'b' => 2];

    expect(CanonicalHasher::hash($first))->toBe(CanonicalHasher::hash($second));
});
