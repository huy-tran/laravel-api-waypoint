<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $quantity
 * @property int|null $unit_price_cents
 */
class OrderLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
