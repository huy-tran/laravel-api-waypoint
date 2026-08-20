<?php

declare(strict_types=1);

namespace Modules\Orders\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
class PlacedBetweenFilter implements Filter
{
    /**
     * @param Builder<Model> $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $dates = is_array($value) ? $value : explode(',', (string) $value);

        if (count($dates) !== 2) {
            return;
        }

        $query->whereBetween('placed_at', [trim($dates[0]), trim($dates[1])]);
    }
}
