<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

/**
 * Converts a Laravel regex rule to a JSON Schema pattern, or refuses.
 *
 * Refusing matters more than converting. JSON Schema patterns are ECMA-262
 * regexes, and a PCRE construct that ECMA does not have either throws in the
 * consumer's regex engine or, worse, silently matches something different, which
 * makes the Central App reject payloads the API would have accepted.
 */
final class RegexTranslator
{
    /**
     * Constructs with no ECMA-262 equivalent, or with different semantics there.
     *
     * @var array<string, string>
     */
    private const PCRE_ONLY = [
        '/\(\?<[=!]/' => 'lookbehind',
        '/\\\\A/' => 'string-start anchor \A',
        '/\\\\z/i' => 'string-end anchor \z',
        '/\\\\Z/' => 'string-end anchor \Z',
        '/\\\\G/' => 'match-start anchor \G',
        '/\\\\K/' => 'match reset \K',
        '/[*+?}]\+/' => 'possessive quantifier',
        '/\(\?>/' => 'atomic group',
        '/\(\?[R0-9]\)/' => 'recursion',
        '/\(\?&/' => 'subroutine call',
        '/\(\?P?[<\'][^>\']+[>\']\(\?/' => 'nested named construct',
        '/\\\\p\{[^}]*\}(?![*+?{])/' => 'unicode property without the u flag',
        '/\(\?\#/' => 'inline comment group',
        '/\\\\[QE]/' => 'quote literal \Q...\E',
    ];

    /**
     * Returns the ECMA pattern, or null when the regex must not be converted.
     */
    public static function toEcma(string $rule): ?string
    {
        $rule = trim($rule);

        if ($rule === '') {
            return null;
        }

        [$pattern, $flags] = self::split($rule);

        if ($pattern === null) {
            return null;
        }

        // "i" is the only inline flag JSON Schema consumers can be relied on to
        // apply, and even that has to be folded into the pattern. Anything else
        // (m, s, x, u) changes matching in ways the pattern alone cannot express.
        $unsupported = str_replace(['i', 'u'], '', $flags);

        if ($unsupported !== '') {
            return null;
        }

        foreach (self::PCRE_ONLY as $needle => $_construct) {
            if (preg_match($needle, $pattern) === 1) {
                return null;
            }
        }

        if (str_contains($flags, 'i')) {
            $pattern = '(?i)'.$pattern;
        }

        return $pattern;
    }

    /**
     * Splits /pattern/flags into its parts, tolerating the delimiters Laravel
     * rules use in practice.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $rule): array
    {
        $delimiter = $rule[0];

        $closing = match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };

        // An undelimited rule is still a valid Laravel regex in some codebases.
        if (! in_array($delimiter, ['/', '#', '~', '%', '(', '[', '{', '<'], true)) {
            return [$rule, ''];
        }

        $end = strrpos($rule, $closing);

        if ($end === false || $end === 0) {
            return [null, ''];
        }

        return [
            substr($rule, 1, $end - 1),
            substr($rule, $end + 1),
        ];
    }

    /**
     * Derives a symbol mask (# digit, ? letter) from a simple anchored pattern, for
     * x-faker.pattern. Returns null when the pattern is anything but a run of
     * literals and single-character classes with fixed repetition, because a
     * half-right mask generates values that fail validation.
     */
    public static function toMask(string $pattern): ?string
    {
        $body = $pattern;

        if (! str_starts_with($body, '^') || ! str_ends_with($body, '$')) {
            return null;
        }

        $body = substr($body, 1, -1);
        $mask = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];

            if ($character === '\\') {
                $next = $body[$i + 1] ?? '';

                $symbol = match ($next) {
                    'd' => '#',
                    'w' => '?',
                    default => null,
                };

                if ($symbol === null) {
                    // An escaped literal: \- or \. and friends.
                    if (preg_match('/[^a-zA-Z0-9]/', $next) !== 1) {
                        return null;
                    }

                    $mask .= $next;
                    $i++;

                    continue;
                }

                $i++;
                [$mask, $i, $ok] = self::appendWithQuantifier($mask, $symbol, $body, $i);

                if (! $ok) {
                    return null;
                }

                continue;
            }

            if ($character === '[') {
                $close = strpos($body, ']', $i);

                if ($close === false) {
                    return null;
                }

                $class = substr($body, $i + 1, $close - $i - 1);

                $symbol = match (true) {
                    $class === '0-9' => '#',
                    $class === 'A-Za-z', $class === 'a-zA-Z' => '?',
                    $class === 'A-Z', $class === 'a-z' => '?',
                    default => null,
                };

                if ($symbol === null) {
                    return null;
                }

                $i = $close;
                [$mask, $i, $ok] = self::appendWithQuantifier($mask, $symbol, $body, $i);

                if (! $ok) {
                    return null;
                }

                continue;
            }

            // Any remaining metacharacter means the pattern is richer than a mask
            // can express, and guessing would produce invalid values.
            if (str_contains('.*+?()|[]{}^$', $character)) {
                return null;
            }

            $mask .= $character;
        }

        return $mask === '' ? null : $mask;
    }

    /**
     * @return array{0: string, 1: int, 2: bool}
     */
    private static function appendWithQuantifier(string $mask, string $symbol, string $body, int $i): array
    {
        $next = $body[$i + 1] ?? '';

        if ($next !== '{') {
            return [$mask.$symbol, $i, true];
        }

        $close = strpos($body, '}', $i);

        if ($close === false) {
            return [$mask, $i, false];
        }

        $quantifier = substr($body, $i + 2, $close - $i - 2);

        // {2,4} is a range: a mask has no way to say "between", so refuse rather
        // than silently pick one end.
        if (preg_match('/^\d+$/', $quantifier) !== 1) {
            return [$mask, $i, false];
        }

        return [$mask.str_repeat($symbol, (int) $quantifier), $close, true];
    }
}
