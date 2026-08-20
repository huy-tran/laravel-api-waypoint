<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapInputName(SnakeCaseMapper::class)]
class OrderLineData extends Data
{
    public function __construct(
        #[Exists('products', 'id')]
        public int $productId,

        #[Min(1), Max(999)]
        public int $quantity,

        /** Overrides the product price when the caller has the pricing ability. */
        #[Min(0)]
        public int|Optional|null $unitPriceCents = null,
    ) {}
}
