<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Orders\Enums\OrderStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property string $reference
 * @property OrderStatus $status
 * @property string $channel
 * @property int $total_cents
 * @property string|null $placed_at
 */
class Order extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'status' => OrderStatus::class,
        'total_cents' => 'integer',
    ];

    /** Bound by uuid, so the compiler must report uuid as the path parameter key. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
