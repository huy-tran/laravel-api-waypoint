<?php

declare(strict_types=1);

namespace Modules\Billing\Data;

use Modules\Billing\Enums\RefundReason;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class RefundOrderData extends Data
{
    public function __construct(
        public int $amountCents,

        public RefundReason $reason,

        public bool $notifyCustomer = true,
    ) {}

    /**
     * amount_cents is bound by the order's total, which is not part of this
     * payload. That is the case the "unresolvable" strategy exists for.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'lte:order_total_cents'],
            'notify_customer' => ['boolean'],
        ];
    }
}
