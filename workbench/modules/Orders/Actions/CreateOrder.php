<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Hygo\ApiWaypoint\Attributes\WaypointResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Modules\Orders\Data\CreateOrderData;
use Modules\Orders\Models\Order;
use Modules\Orders\Transformers\OrderTransformer;

/**
 * Create an order.
 */
#[WaypointResponse(status: 201, transformer: OrderTransformer::class)]
class CreateOrder
{
    use AsAction;

    public function handle(CreateOrderData $data): Order
    {
        return new Order([
            'uuid' => (string) str()->uuid(),
            'reference' => $data->reference,
            'total_cents' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function asController(CreateOrderData $data): array
    {
        return ['data' => ['id' => $this->handle($data)->uuid]];
    }
}
