<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Ramsey\Uuid\UuidInterface;
use Spatie\LaravelData\Optional;
use Stringable;
use Symfony\Component\Uid\Uuid;

/**
 * The cast-to-input-type table.
 *
 * The schema must describe what the endpoint *accepts*, not what the PHP property
 * holds. A CarbonImmutable property accepts an ISO string; a Money value object
 * accepts an integer. Describing the PHP type would send the Central App's
 * generator off to build a value the endpoint cannot parse.
 *
 * Unknown class types fall back to string with a cast_input_assumed warning,
 * which is the honest answer: the compiler guessed.
 */
final class CastInputTypes
{
    /**
     * Class type => the JSON Schema fragment describing its accepted input.
     *
     * @var array<class-string|string, array<string, mixed>>
     */
    private const TABLE = [
        DateTimeInterface::class => ['type' => 'string', 'format' => 'date-time'],
        DateTimeImmutable::class => ['type' => 'string', 'format' => 'date-time'],
        DateTime::class => ['type' => 'string', 'format' => 'date-time'],
        Carbon::class => ['type' => 'string', 'format' => 'date-time'],
        CarbonImmutable::class => ['type' => 'string', 'format' => 'date-time'],
        CarbonInterface::class => ['type' => 'string', 'format' => 'date-time'],
        DateInterval::class => ['type' => 'string', 'x-laravel' => ['cast' => 'interval']],
        Collection::class => ['type' => 'array'],
        Fluent::class => ['type' => 'object'],
        \Illuminate\Support\Stringable::class => ['type' => 'string'],
        Stringable::class => ['type' => 'string'],
        UuidInterface::class => ['type' => 'string', 'format' => 'uuid'],
        Uuid::class => ['type' => 'string', 'format' => 'uuid'],
        UploadedFile::class => ['type' => 'string', 'x-laravel' => ['upload' => true]],
        Optional::class => [],
    ];

    /** Builtin PHP types, which need no cast reasoning at all. */
    private const BUILTIN = [
        'string' => 'string',
        'int' => 'integer',
        'integer' => 'integer',
        'float' => 'number',
        'double' => 'number',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'array' => 'array',
        'iterable' => 'array',
        'object' => 'object',
        'mixed' => null,
        'null' => 'null',
    ];

    public static function isBuiltin(string $type): bool
    {
        return array_key_exists(strtolower($type), self::BUILTIN);
    }

    public static function builtin(string $type): ?string
    {
        return self::BUILTIN[strtolower($type)] ?? null;
    }

    /**
     * Returns the accepted-input fragment for a class type, or null when the
     * compiler has no entry and the caller must warn.
     *
     * @return array<string, mixed>|null
     */
    public static function for(string $type): ?array
    {
        $type = ltrim($type, '\\');

        if (isset(self::TABLE[$type])) {
            return self::TABLE[$type];
        }

        // A subclass of a known type accepts the same input: a project's own
        // AppDate extends CarbonImmutable and still arrives as an ISO string.
        foreach (self::TABLE as $known => $fragment) {
            if (class_exists($known) || interface_exists($known)) {
                if (is_a($type, $known, true)) {
                    return $fragment;
                }
            }
        }

        return null;
    }

    public static function isUpload(string $type): bool
    {
        $type = ltrim($type, '\\');

        return is_a($type, UploadedFile::class, true)
            || is_a($type, \Symfony\Component\HttpFoundation\File\UploadedFile::class, true);
    }
}
