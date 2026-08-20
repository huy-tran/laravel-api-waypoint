<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Hygo\ApiWaypoint\Attributes\WaypointResponse;
use Hygo\ApiWaypoint\Compiler\Query\QueryConfig;
use Hygo\ApiWaypoint\Concerns\HasWaypointQuery;
use Hygo\ApiWaypoint\Contracts\ProvidesWaypointQuery;
use Lorisleiva\Actions\Concerns\AsAction;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Filters\PlacedBetweenFilter;
use Modules\Orders\Models\Order;
use Modules\Orders\Transformers\OrderTransformer;

/**
 * List orders.
 */
#[WaypointResponse(transformer: OrderTransformer::class)]
class ListOrders implements ProvidesWaypointQuery
{
    use AsAction;
    use HasWaypointQuery;

    public static function waypointQuery(): QueryConfig
    {
        return QueryConfig::make()
            ->exactFilter('status', values: OrderStatus::class, multiple: true)
            ->partialFilter('reference')
            ->partialFilter('customer.name', relation: 'customer')
            ->customFilter('placed_between', PlacedBetweenFilter::class, valueHint: 'date_range_csv')
            ->sorts(['placed_at' => 'desc', 'total_cents', 'reference'])
            ->includes(['customer', 'lines', 'lines.product'])
            ->countInclude('payments')
            ->fields(['orders' => ['id', 'reference', 'status', 'total_cents'], 'customers' => ['id', 'name']])
            ->pagination(perPage: 15, max: 100);
    }

    public function asController(): mixed
    {
        return $this->waypointPaginate($this->queryBuilder(Order::class));
    }
}
