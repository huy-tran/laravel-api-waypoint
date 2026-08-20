<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Support;

/**
 * The closed set of diagnostic warning codes.
 *
 * Closed on purpose: the Central App renders each code differently, and
 * waypoint:check --fail-on-warning is only meaningful if the vocabulary is
 * stable. A compiler emitting a code absent from here fails the test suite.
 */
final class WarningCode
{
    /** Route has no name, so its endpoint id is derived and unstable across refactors. */
    public const UNNAMED_ROUTE = 'unnamed_route';

    /** Action class could not be reflected. */
    public const UNSUPPORTED_ACTION = 'unsupported_action';

    /** Attribute-derived facts and rule()-derived facts disagree; attributes won. */
    public const RULE_CONFLICT = 'rule_conflict';

    /** Property uses a cast the compiler has no input-type mapping for; assumed string. */
    public const CAST_INPUT_ASSUMED = 'cast_input_assumed';

    /** Rule is a closure, Rule object or conditional the compiler cannot read. */
    public const OPAQUE_RULE = 'opaque_rule';

    /** Query config came from the recording spy, not the contract. Lower confidence. */
    public const PROBED_QUERY_CONFIG = 'probed_query_config';

    /** A collection GET endpoint declares no query contract. */
    public const NO_QUERY_CONFIG = 'no_query_config';

    /** A regex rule uses PCRE-only constructs and was not converted to a pattern. */
    public const PCRE_ONLY_PATTERN = 'pcre_only_pattern';

    /** A field cannot be generated: it depends on state outside the payload. */
    public const UNRESOLVABLE_FIELD = 'unresolvable_field';

    /** Response shape cannot be derived statically (Fractal). Snapshot to enable diffing. */
    public const OPAQUE_RESPONSE = 'opaque_response';

    /** A referenced Data class could not be compiled. */
    public const UNCOMPILABLE_DATA_CLASS = 'uncompilable_data_class';

    /** Endpoint accepts file uploads, which v1 does not describe. */
    public const MULTIPART_ENDPOINT = 'multipart_endpoint';

    /** A Data class references itself; the cycle was cut with a $ref. */
    public const RECURSIVE_DATA_CLASS = 'recursive_data_class';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::UNNAMED_ROUTE,
            self::UNSUPPORTED_ACTION,
            self::RULE_CONFLICT,
            self::CAST_INPUT_ASSUMED,
            self::OPAQUE_RULE,
            self::PROBED_QUERY_CONFIG,
            self::NO_QUERY_CONFIG,
            self::PCRE_ONLY_PATTERN,
            self::UNRESOLVABLE_FIELD,
            self::OPAQUE_RESPONSE,
            self::UNCOMPILABLE_DATA_CLASS,
            self::MULTIPART_ENDPOINT,
            self::RECURSIVE_DATA_CLASS,
        ];
    }
}
