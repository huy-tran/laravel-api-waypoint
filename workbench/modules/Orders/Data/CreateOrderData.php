<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

use Carbon\CarbonImmutable;
use Hygo\ApiWaypoint\Attributes\WaypointFaker;
use Modules\Orders\Enums\OrderChannel;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapInputName(SnakeCaseMapper::class)]
class CreateOrderData extends Data
{
    public function __construct(
        /** Existing customer to place the order against. */
        #[Exists('customers', 'uuid')]
        public string $customerId,

        public OrderChannel $channel,

        /** @var DataCollection<int, OrderLineData> */
        #[DataCollectionOf(OrderLineData::class)]
        public DataCollection $lines,

        #[Max(32)]
        #[WaypointFaker(strategy: 'pattern', pattern: 'ORD-######', includeProbability: 0.6)]
        public ?string $reference = null,

        #[Max(40)]
        public string|Optional|null $purchaseOrderNo = null,

        #[Max(500)]
        public ?string $notes = null,

        public ?CarbonImmutable $shipAt = null,

        /** @var array<string, string>|null */
        public ?array $metadata = null,

        /** Output only: must never appear in the input schema. */
        #[Computed]
        public string $summary = '',

        /** Output only: must never appear in the input schema. */
        public Lazy|string|null $auditTrail = null,
    ) {}

    /**
     * Rules that only exist here, to prove the compiler merges both sources.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:32', 'regex:/^ORD-[0-9]{6}$/', 'unique:orders,reference'],
            'purchase_order_no' => ['required_if:channel,phone', 'nullable', 'string', 'max:40'],
            'ship_at' => ['nullable', 'date', 'after:today'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
