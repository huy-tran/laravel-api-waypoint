<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Data\RegexTranslator;

it('unwraps common delimiters', function (string $rule, string $expected): void {
    expect(RegexTranslator::toEcma($rule))->toBe($expected);
})->with([
    'slash' => ['/^ORD-[0-9]{6}$/', '^ORD-[0-9]{6}$'],
    'hash' => ['#^ORD-[0-9]{6}$#', '^ORD-[0-9]{6}$'],
    'tilde' => ['~^abc$~', '^abc$'],
    'undelimited' => ['^abc$', '^abc$'],
]);

it('folds a case-insensitive flag into the pattern', function (): void {
    expect(RegexTranslator::toEcma('/^abc$/i'))->toBe('(?i)^abc$');
});

it('refuses flags that change matching in ways a pattern cannot express', function (string $rule): void {
    expect(RegexTranslator::toEcma($rule))->toBeNull();
})->with(['/^abc$/m', '/a.c/s', '/a b c/x', '/abc/im']);

it('refuses PCRE-only constructs', function (string $rule): void {
    expect(RegexTranslator::toEcma($rule))->toBeNull();
})->with([
    'lookbehind' => ['/(?<=USD)[0-9]+/'],
    'negative lookbehind' => ['/(?<!x)abc/'],
    'string start' => ['/\Aabc/'],
    'string end lower' => ['/abc\z/'],
    'string end upper' => ['/abc\Z/'],
    'match start' => ['/\Gabc/'],
    'match reset' => ['/abc\Kdef/'],
    'possessive star' => ['/a*+b/'],
    'possessive plus' => ['/a++b/'],
    'possessive brace' => ['/a{2,3}+b/'],
    'atomic group' => ['/(?>abc)/'],
    'recursion' => ['/(?R)/'],
    'subroutine' => ['/(?&name)/'],
    'inline comment' => ['/abc(?#note)/'],
    'quote literal' => ['/\Qa.b\E/'],
]);

it('allows lookahead, which ECMA has', function (): void {
    expect(RegexTranslator::toEcma('/^(?=.*[0-9]).{8,}$/'))->toBe('^(?=.*[0-9]).{8,}$');
});

it('refuses an empty or unterminated rule', function (): void {
    expect(RegexTranslator::toEcma(''))->toBeNull()
        ->and(RegexTranslator::toEcma('/'))->toBeNull();
});

it('derives a mask from a literal-plus-quantifier pattern', function (string $pattern, ?string $mask): void {
    expect(RegexTranslator::toMask($pattern))->toBe($mask);
})->with([
    'prefixed digits' => ['^ORD-[0-9]{6}$', 'ORD-######'],
    'bare digits' => ['^[0-9]{4}$', '####'],
    'letters' => ['^[A-Z]{3}$', '???'],
    'mixed' => ['^[A-Z]{2}[0-9]{3}$', '??###'],
    'escaped digit class' => ['^\d{4}$', '####'],
    'single char class' => ['^[0-9]$', '#'],
]);

it('refuses a mask for anything richer than a mask can express', function (string $pattern): void {
    expect(RegexTranslator::toMask($pattern))->toBeNull();
})->with([
    'alternation' => ['^(foo|bar)$'],
    'unanchored' => ['[0-9]{4}'],
    'variable repetition' => ['^[0-9]{2,4}$'],
    'star' => ['^[0-9]*$'],
    'plus' => ['^[0-9]+$'],
    'any char' => ['^.{4}$'],
    'unknown class' => ['^[aeiou]{2}$'],
]);
