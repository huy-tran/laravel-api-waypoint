<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Modules\Orders\Data\AttachmentData;
use Modules\Orders\Models\Order;

/**
 * Attach a document to an order.
 *
 * Multipart, so waypoint must list it in diagnostics rather than describing it.
 */
class AttachDocument
{
    use AsAction;

    /**
     * @return array<string, mixed>
     */
    public function asController(Order $order, AttachmentData $data): array
    {
        return ['order' => $order->uuid, 'caption' => $data->caption];
    }
}
