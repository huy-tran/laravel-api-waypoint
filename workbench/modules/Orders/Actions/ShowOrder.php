<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Hygo\ApiWaypoint\Attributes\WaypointResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Modules\Orders\Models\Order;
use Modules\Orders\Transformers\OrderTransformer;

/**
 * Show a single order.
 */
#[WaypointResponse(transformer: OrderTransformer::class)]
class ShowOrder
{
    use AsAction;

    public function asController(Order $order): Order
    {
        return $order;
    }
}
