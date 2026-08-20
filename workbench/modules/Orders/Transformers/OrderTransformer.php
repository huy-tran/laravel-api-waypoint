<?php

declare(strict_types=1);

namespace Modules\Orders\Transformers;

use League\Fractal\TransformerAbstract;
use Modules\Orders\Models\Order;

class OrderTransformer extends TransformerAbstract
{
    /** @var array<int, string> */
    protected array $availableIncludes = ['customer', 'lines', 'payments', 'lines.product'];

    /** @var array<int, string> */
    protected array $defaultIncludes = ['customer'];

    /**
     * @return array<string, mixed>
     */
    public function transform(Order $order): array
    {
        return [
            'id' => $order->uuid,
            'reference' => $order->reference,
            'status' => $order->status,
            'total_cents' => $order->total_cents,
        ];
    }
}
