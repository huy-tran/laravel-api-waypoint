<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Tests\Fixtures\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * A tree. Compiling this naively recurses until the stack gives out, so the
 * registry's cycle guard has to cut it with a $ref back to the component that is
 * still being built.
 */
class CategoryData extends Data
{
    public function __construct(
        public string $name,

        /** @var DataCollection<int, CategoryData>|null */
        #[DataCollectionOf(CategoryData::class)]
        public ?DataCollection $children = null,

        public ?CategoryData $parent = null,
    ) {}
}
