<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

/**
 * The result of folding one property's validation rules.
 *
 * Deliberately a mutable bag rather than a schema fragment: the caller merges it
 * with facts derived from the PHP type, and needs the parts separated to know
 * which source won.
 */
class MappedRules
{
    /** JSON Schema keywords: type, format, pattern, minimum, enum, ... */
    /** @var array<string, mixed> */
    public array $schema = [];

    /** The x-laravel extension block for this property. */
    /** @var array<string, mixed> */
    public array $laravel = [];

    /** Generation hints derived from rules, consumed by FakerHintResolver. */
    /** @var array<string, mixed> */
    public array $faker = [];

    /** Every rule seen, verbatim, in the order given. */
    /** @var array<int, string> */
    public array $rules = [];

    /** Warnings raised while mapping: [['code' => ..., 'detail' => ...], ...] */
    /** @var array<int, array{code: string, detail: string}> */
    public array $warnings = [];

    public bool $required = false;

    public bool $nullable = false;

    public bool $optional = false;

    /** Set by "present": the key must be sent, its value may be anything. */
    public bool $presentOnly = false;

    /** Set by file/image/mimes/mimetypes. Marks the whole endpoint multipart. */
    public bool $multipart = false;

    /** Set by "confirmed": the compiler emits a {key}_confirmation sibling. */
    public bool $confirmed = false;

    /** Set by "distinct" on a nested rule: the parent array gets uniqueItems. */
    public bool $distinct = false;

    /** The type the mapper settled on, before nullability is folded in. */
    public ?string $resolvedType = null;

    public function warn(string $code, string $detail): void
    {
        $this->warnings[] = ['code' => $code, 'detail' => $detail];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addConditionalRule(array $entry): void
    {
        $this->laravel['conditional_rules'][] = $entry;
    }
}
