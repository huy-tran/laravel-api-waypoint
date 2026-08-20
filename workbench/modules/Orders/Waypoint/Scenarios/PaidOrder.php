<?php

declare(strict_types=1);

namespace Modules\Orders\Waypoint\Scenarios;

use Hygo\ApiWaypoint\Contracts\WaypointScenario;
use Modules\Orders\Enums\OrderChannel;
use Modules\Orders\Models\Customer;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderLine;
use Modules\Orders\Models\Product;

class PaidOrder implements WaypointScenario
{
    public static function description(): string
    {
        return 'A paid order with lines, ready to refund.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function parameters(): array
    {
        return [
            'type' => 'object',
            'required' => [],
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => ['web', 'phone', 'in_store'],
                    'default' => 'web',
                ],
                'line_count' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                    'default' => 2,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public function run(array $parameters): array
    {
        $customer = Customer::create([
            'uuid' => (string) str()->uuid(),
            'name' => 'Waypoint Customer',
            'email' => 'waypoint.customer@example.test',
            'status' => 'active',
        ]);

        $order = Order::create([
            'uuid' => (string) str()->uuid(),
            'customer_id' => $customer->getKey(),
            'reference' => 'ORD-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => 'paid',
            'channel' => $parameters['channel'] ?? OrderChannel::Web->value,
            'total_cents' => 0,
        ]);

        $lines = [];
        $total = 0;

        for ($i = 0; $i < (int) ($parameters['line_count'] ?? 2); $i++) {
            $product = Product::create([
                'name' => 'Waypoint Product '.($i + 1),
                'price_cents' => 1500 + ($i * 250),
                'is_active' => true,
            ]);

            $line = OrderLine::create([
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price_cents' => $product->price_cents,
            ]);

            $total += (int) $product->price_cents;
            $lines[] = ['key' => $line->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1];
        }

        $order->update(['total_cents' => $total]);

        return [
            [
                'model' => Customer::class,
                'key' => $customer->getKey(),
                'route_key' => $customer->uuid,
                'attributes' => ['uuid' => $customer->uuid, 'name' => $customer->name],
            ],
            [
                'model' => Order::class,
                'key' => $order->getKey(),
                'route_key' => $order->uuid,
                'attributes' => [
                    'uuid' => $order->uuid,
                    'reference' => $order->reference,
                    'status' => 'paid',
                    'total_cents' => $total,
                ],
                'related' => ['lines' => $lines],
            ],
        ];
    }
}
