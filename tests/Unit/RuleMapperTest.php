<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Data\MappedRules;
use Hygo\ApiWaypoint\Compiler\Data\RuleMapper;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Orders\Enums\OrderChannel;
use Modules\Orders\Models\Order;

function mapRules(array $rules, ?string $typeHint = null, array $siblings = []): MappedRules
{
    return (new RuleMapper)->map($rules, $typeHint, $siblings);
}

it('adds required to the parent required list', function (): void {
    expect(mapRules(['required'])->required)->toBeTrue();
});

it('treats sometimes as optional', function (): void {
    $mapped = mapRules(['sometimes', 'string']);

    expect($mapped->optional)->toBeTrue()
        ->and($mapped->required)->toBeFalse();
});

it('treats present as required but value-agnostic', function (): void {
    $mapped = mapRules(['present']);

    expect($mapped->required)->toBeTrue()
        ->and($mapped->laravel['present_only'])->toBeTrue();
});

it('records nullable', function (): void {
    expect(mapRules(['nullable', 'string'])->nullable)->toBeTrue();
});

it('maps the scalar type rules', function (array $rules, string $type): void {
    expect(mapRules($rules)->schema['type'])->toBe($type);
})->with([
    'string' => [['string'], 'string'],
    'integer' => [['integer'], 'integer'],
    'int' => [['int'], 'integer'],
    'numeric' => [['numeric'], 'number'],
    'boolean' => [['boolean'], 'boolean'],
    'array' => [['array'], 'array'],
]);

it('maps decimal:p,s to a multipleOf', function (): void {
    $mapped = mapRules(['decimal:2,4']);

    expect($mapped->schema['type'])->toBe('number')
        ->and($mapped->schema['multipleOf'])->toBe(1.0e-4);
});

it('maps min and max by the resolved type', function (array $rules, array $expected): void {
    expect(mapRules($rules)->schema)->toMatchArray($expected);
})->with([
    'string length' => [['string', 'min:2', 'max:50'], ['minLength' => 2, 'maxLength' => 50]],
    'integer bounds' => [['integer', 'min:1', 'max:999'], ['minimum' => 1, 'maximum' => 999]],
    'array counts' => [['array', 'min:1', 'max:50'], ['minItems' => 1, 'maxItems' => 50]],
]);

it('maps between to both bounds', function (): void {
    expect(mapRules(['integer', 'between:5,10'])->schema)
        ->toMatchArray(['minimum' => 5, 'maximum' => 10]);
});

it('maps size to an exact bound pair for the resolved type', function (): void {
    expect(mapRules(['string', 'size:8'])->schema)
        ->toMatchArray(['minLength' => 8, 'maxLength' => 8]);

    expect(mapRules(['array', 'size:3'])->schema)
        ->toMatchArray(['minItems' => 3, 'maxItems' => 3]);
});

it('maps literal comparisons to exclusive and inclusive bounds', function (string $rule, string $keyword): void {
    expect(mapRules(['integer', $rule])->schema[$keyword])->toBe(5);
})->with([
    'gt' => ['gt:5', 'exclusiveMinimum'],
    'gte' => ['gte:5', 'minimum'],
    'lt' => ['lt:5', 'exclusiveMaximum'],
    'lte' => ['lte:5', 'maximum'],
]);

it('records a field-referencing comparison as a conditional rule', function (): void {
    $mapped = mapRules(['integer', 'lte:maximum_cents'], null, ['maximum_cents', 'amount_cents']);

    expect($mapped->laravel['conditional_rules'][0])->toMatchArray([
        'rule' => 'lte',
        'field' => 'maximum_cents',
        'resolvable' => true,
    ])->and($mapped->schema)->not->toHaveKey('maximum');
});

it('marks a comparison against a non-sibling field as unresolvable', function (): void {
    $mapped = mapRules(['integer', 'lte:order_total_cents'], null, ['amount_cents', 'reason']);

    expect($mapped->faker['strategy'])->toBe('unresolvable')
        ->and($mapped->faker['reason'])->toContain('order_total_cents')
        ->and($mapped->warnings[0]['code'])->toBe(WarningCode::UNRESOLVABLE_FIELD);
});

it('maps digits and digits_between to anchored patterns', function (): void {
    expect(mapRules(['digits:4'])->schema['pattern'])->toBe('^[0-9]{4}$');
    expect(mapRules(['digits_between:2,5'])->schema['pattern'])->toBe('^[0-9]{2,5}$');
});

it('maps in to an enum', function (): void {
    expect(mapRules(['in:csv,xlsx'])->schema['enum'])->toBe(['csv', 'xlsx']);
});

it('casts in values to the resolved type', function (): void {
    expect(mapRules(['integer', 'in:1,2,3'])->schema['enum'])->toBe([1, 2, 3]);
});

it('maps Rule::enum to an enum plus its class', function (): void {
    $mapped = mapRules(['required', Rule::enum(OrderChannel::class)]);

    expect($mapped->schema['enum'])->toBe(['web', 'phone', 'in_store'])
        ->and($mapped->laravel['enum_class'])->toBe(OrderChannel::class)
        ->and($mapped->schema['type'])->toBe('string');
});

it('maps the format rules', function (array $rules, string $keyword, string $value): void {
    expect(mapRules($rules)->schema[$keyword])->toBe($value);
})->with([
    'email' => [['email'], 'format', 'email'],
    'url' => [['url'], 'format', 'uri'],
    'active_url' => [['active_url'], 'format', 'uri'],
    'uuid' => [['uuid'], 'format', 'uuid'],
    'ipv4' => [['ipv4'], 'format', 'ipv4'],
    'ipv6' => [['ipv6'], 'format', 'ipv6'],
    'ip' => [['ip'], 'format', 'ip'],
    'date' => [['date'], 'format', 'date-time'],
]);

it('maps ulid to its pattern', function (): void {
    expect(mapRules(['ulid'])->schema['pattern'])->toBe('^[0-9A-HJKMNP-TV-Z]{26}$');
});

it('maps json to a string carrying a laravel flag', function (): void {
    $mapped = mapRules(['json']);

    expect($mapped->schema['type'])->toBe('string')
        ->and($mapped->laravel['json'])->toBeTrue();
});

it('maps timezone to its generation strategy', function (): void {
    expect(mapRules(['timezone'])->faker['strategy'])->toBe('timezone');
});

it('maps date_format and drops the weaker date-time format claim', function (): void {
    $mapped = mapRules(['date', 'date_format:Y-m-d']);

    expect($mapped->laravel['date_format'])->toBe('Y-m-d')
        ->and($mapped->faker['format'])->toBe('Y-m-d')
        ->and($mapped->schema)->not->toHaveKey('format');
});

it('maps date bounds into laravel and faker blocks', function (): void {
    $mapped = mapRules(['date', 'after:today', 'before:+30 days']);

    expect($mapped->laravel['date_bounds'])->toBe(['after' => 'today', 'before' => '+30 days'])
        ->and($mapped->faker['range'])->toBe(['after' => 'today', 'before' => '+30 days']);
});

it('converts a safe regex to a pattern', function (): void {
    expect(mapRules(['regex:/^ORD-[0-9]{6}$/'])->schema['pattern'])->toBe('^ORD-[0-9]{6}$');
});

it('refuses a PCRE-only regex and says why', function (string $rule): void {
    $mapped = mapRules([$rule]);

    expect($mapped->schema)->not->toHaveKey('pattern')
        ->and($mapped->faker['strategy'])->toBe('unresolvable')
        ->and($mapped->faker['reason'])->toBe('pcre_only_pattern')
        ->and($mapped->warnings[0]['code'])->toBe(WarningCode::PCRE_ONLY_PATTERN);

    // The rule itself is still reported verbatim, so nothing is lost.
    expect($mapped->rules)->toContain($rule);
})->with([
    'lookbehind' => ['regex:/(?<=x)abc/'],
    'string-start anchor' => ['regex:/\Aabc/'],
    'string-end anchor' => ['regex:/abc\z/'],
    'possessive quantifier' => ['regex:/a++b/'],
    'atomic group' => ['regex:/(?>abc)/'],
    'multiline flag' => ['regex:/^abc$/m'],
    'extended flag' => ['regex:/a b c/x'],
]);

it('never turns not_regex into a pattern', function (): void {
    $mapped = mapRules(['not_regex:/^admin/']);

    expect($mapped->schema)->not->toHaveKey('pattern')
        ->and($mapped->laravel['not_regex'])->toBe('/^admin/');
});

it('maps starts_with and ends_with to anchored alternations', function (): void {
    expect(mapRules(['starts_with:ORD,INV'])->schema['pattern'])->toBe('^(ORD|INV)');
    expect(mapRules(['ends_with:.pdf,.png'])->schema['pattern'])->toBe('(\.pdf|\.png)$');
});

it('maps the alpha family to patterns', function (string $rule): void {
    expect(mapRules([$rule])->schema['pattern'])->toStartWith('^[');
})->with(['alpha', 'alpha_num', 'alpha_dash']);

it('maps exists to a laravel block and a reference strategy', function (): void {
    $mapped = mapRules(['exists:customers,uuid']);

    expect($mapped->laravel['exists'])->toBe(['table' => 'customers', 'column' => 'uuid'])
        ->and($mapped->faker['strategy'])->toBe('reference')
        ->and($mapped->faker['reference'])->toBe(['table' => 'customers', 'column' => 'uuid']);
});

it('defaults the exists column to id when none is given', function (): void {
    expect(mapRules(['exists:products'])->laravel['exists'])
        ->toBe(['table' => 'products', 'column' => 'id']);
});

it('strips a connection prefix from an exists table', function (): void {
    expect(mapRules(['exists:reporting.orders,uuid'])->laravel['exists'])
        ->toBe(['table' => 'orders', 'column' => 'uuid']);
});

it('resolves a model class in an exists rule to its table', function (): void {
    expect(mapRules(['exists:'.Order::class.',uuid'])->laravel['exists'])
        ->toBe(['table' => 'orders', 'column' => 'uuid']);
});

it('maps unique to a laravel block and a uniqueness flag', function (): void {
    $mapped = mapRules(['unique:orders,reference']);

    expect($mapped->laravel['unique'])->toBe(['table' => 'orders', 'column' => 'reference'])
        ->and($mapped->faker['unique'])->toBeTrue();
});

it('flags confirmed so the compiler can emit the sibling', function (): void {
    expect(mapRules(['confirmed'])->confirmed)->toBeTrue();
});

it('maps same and different to mirror and distinct_from', function (): void {
    $same = mapRules(['same:password'], null, ['password']);
    expect($same->faker['strategy'])->toBe('mirror')
        ->and($same->faker['mirrors'])->toBe('password');

    $different = mapRules(['different:username'], null, ['username']);
    expect($different->faker['strategy'])->toBe('distinct_from');
});

it('maps the conditional rule family', function (string $rule, string $key): void {
    $mapped = mapRules([$rule]);

    expect($mapped->laravel['conditional_rules'][0]['field'])->toBe('channel')
        ->and($mapped->faker[$key]['field'])->toBe('channel');
})->with([
    'required_if' => ['required_if:channel,phone', 'required_when'],
    'required_unless' => ['required_unless:channel,web', 'required_when'],
    'required_with' => ['required_with:channel', 'required_when'],
    'required_with_all' => ['required_with_all:channel', 'required_when'],
    'required_without' => ['required_without:channel', 'required_when'],
    'prohibited_if' => ['prohibited_if:channel,web', 'omit_when'],
    'prohibited_unless' => ['prohibited_unless:channel,phone', 'omit_when'],
    'missing_if' => ['missing_if:channel,web', 'omit_when'],
]);

it('flags distinct for the parent array', function (): void {
    expect(mapRules(['distinct'])->distinct)->toBeTrue();
});

it('maps accepted to its truthy enum', function (): void {
    expect(mapRules(['accepted'])->schema['enum'])->toBe([true, 'yes', 'on', 1]);
});

it('flags the upload rules as multipart and maps nothing else', function (string $rule): void {
    $mapped = mapRules([$rule]);

    expect($mapped->multipart)->toBeTrue()
        ->and($mapped->schema)->not->toHaveKey('format');
})->with(['file', 'image', 'mimes:pdf,png', 'mimetypes:application/pdf']);

it('preserves an unlisted rule verbatim without inventing a keyword', function (): void {
    $mapped = mapRules(['string', 'lowercase', 'doesnt_start_with:x']);

    expect($mapped->rules)->toContain('lowercase', 'doesnt_start_with:x')
        ->and($mapped->schema)->toBe(['type' => 'string']);
});

it('warns on a closure rule', function (): void {
    $mapped = mapRules(['string', static fn ($attribute, $value, $fail) => null]);

    expect($mapped->warnings[0]['code'])->toBe(WarningCode::OPAQUE_RULE);
});

it('warns on a custom rule object with no string form', function (): void {
    $rule = new class implements ValidationRule
    {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    expect(mapRules([$rule])->warnings[0]['code'])->toBe(WarningCode::OPAQUE_RULE);
});

it('splits a pipe-delimited rule string', function (): void {
    $mapped = mapRules(['nullable|string|max:50']);

    expect($mapped->nullable)->toBeTrue()
        ->and($mapped->schema['type'])->toBe('string')
        ->and($mapped->schema['maxLength'])->toBe(50);
});

it('produces an identical schema whatever order the rules arrive in', function (): void {
    $a = mapRules(['nullable', 'string', 'max:50', 'email']);
    $b = mapRules(['max:50', 'email', 'string', 'nullable']);
    $c = mapRules(['email', 'nullable', 'max:50', 'string']);

    expect($b->schema)->toBe($a->schema)
        ->and($c->schema)->toBe($a->schema)
        ->and($b->nullable)->toBe($a->nullable)
        ->and($c->nullable)->toBe($a->nullable);
});

it('resolves max by type even when the type rule comes last', function (): void {
    // The reason the mapper runs two passes: "max:50" means maxItems here, and it
    // must not matter that "array" was declared after it.
    expect(mapRules(['max:50', 'array'])->schema)->toMatchArray(['maxItems' => 50]);
});

it('falls back to the PHP type hint when no rule settles the type', function (): void {
    expect(mapRules(['required', 'max:10'], 'integer')->schema)
        ->toMatchArray(['type' => 'integer', 'maximum' => 10]);
});
