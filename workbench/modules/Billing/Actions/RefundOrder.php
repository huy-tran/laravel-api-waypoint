<?php

declare(strict_types=1);

namespace Modules\Billing\Actions;

use Hygo\ApiWaypoint\Attributes\WaypointPrecondition;
use Hygo\ApiWaypoint\Attributes\WaypointResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Modules\Billing\Data\RefundOrderData;
use Modules\Billing\Transformers\RefundTransformer;
use Modules\Orders\Models\Order;

/**
 * Refund a paid order.
 */
#[WaypointResponse(status: 202, transformer: RefundTransformer::class, errors: [409])]
#[WaypointPrecondition('Order must be in the paid state', scenario: 'paid_order')]
class RefundOrder
{
    use AsAction;

    /**
     * @return array<string, mixed>
     */
    public function asController(Order $order, RefundOrderData $data): array
    {
        return [
            'order' => $order->uuid,
            'amount_cents' => $data->amountCents,
            'reason' => $data->reason->value,
        ];
    }
}
