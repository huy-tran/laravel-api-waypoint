<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

use BackedEnum;
use ReflectionEnum;
use Throwable;
use UnitEnum;

/**
 * Reads the accepted input values of a PHP enum.
 *
 * A backed enum accepts its backing values over the wire, not its case names, so
 * that is what the schema must describe. A pure enum has nothing else to offer,
 * so its case names are the accepted input.
 */
final class EnumReader
{
    /** @var array<string, array<int, string|int>> */
    private static array $values = [];

    /** @var array<string, string|null> */
    private static array $backing = [];

    /**
     * @param class-string $enum
     * @return array<int, string|int>
     */
    public static function values(string $enum): array
    {
        if (isset(self::$values[$enum])) {
            return self::$values[$enum];
        }

        if (! enum_exists($enum)) {
            return self::$values[$enum] = [];
        }

        /** @var array<int, UnitEnum> $cases */
        $cases = $enum::cases();

        return self::$values[$enum] = array_map(
            static fn (UnitEnum $case): string|int => $case instanceof BackedEnum ? $case->value : $case->name,
            $cases
        );
    }

    /**
     * "int", "string", or null for a pure enum.
     *
     * @param class-string $enum
     */
    public static function backingType(string $enum): ?string
    {
        if (array_key_exists($enum, self::$backing)) {
            return self::$backing[$enum];
        }

        if (! enum_exists($enum)) {
            return self::$backing[$enum] = null;
        }

        try {
            $reflection = new ReflectionEnum($enum);

            return self::$backing[$enum] = $reflection->isBacked()
                ? (string) $reflection->getBackingType()
                : null;
        } catch (Throwable) {
            return self::$backing[$enum] = null;
        }
    }

    /**
     * The JSON Schema type an enum's accepted input takes.
     *
     * @param class-string $enum
     */
    public static function jsonType(string $enum): string
    {
        return self::backingType($enum) === 'int' ? 'integer' : 'string';
    }

    /** Test seam: the memo is process-wide and would otherwise leak between tests. */
    public static function flush(): void
    {
        self::$values = [];
        self::$backing = [];
    }
}
