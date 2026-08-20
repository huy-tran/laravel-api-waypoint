<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Tests\Fixtures\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Properties whose PHP type is not what the endpoint accepts over the wire.
 *
 * The schema has to describe the accepted input. A CarbonImmutable property
 * accepts an ISO string; a backed enum accepts its backing value. Describing the
 * PHP type would send the Central App off to build something unparseable.
 */
class CastWideningData extends Data
{
    public function __construct(
        public CarbonImmutable $occurredAt,
        public Priority $priority,
        public Weekday $weekday,
        public Collection $tags,
        public ?UnmappedValueObject $custom = null,
    ) {}
}

enum Priority: int
{
    case Low = 1;
    case Normal = 5;
    case High = 9;
}

/** A pure enum: its case names are the accepted input, since it has nothing else. */
enum Weekday
{
    case Monday;
    case Tuesday;
}

/** No entry in the cast-input table, so the compiler must guess and say so. */
class UnmappedValueObject
{
    public function __construct(public string $value = '') {}
}
