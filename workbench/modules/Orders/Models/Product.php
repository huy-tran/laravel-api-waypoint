<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $price_cents
 * @property bool $is_active
 */
class Product extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
        'price_cents' => 'integer',
    ];
}
