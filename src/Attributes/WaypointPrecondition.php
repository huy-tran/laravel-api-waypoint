<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Attributes;

use Attribute;

/**
 * Declares state an endpoint needs before it can succeed, and the scenario that
 * creates it.
 *
 * This is what turns "422, no idea why" into "run paid_order first" in the
 * Central App.
 *
 *   #[WaypointPrecondition('Order must be in the paid state', scenario: 'paid_order')]
 *   class RefundOrder { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class WaypointPrecondition
{
    public function __construct(
        public string $description,
        public ?string $scenario = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'description' => $this->description,
            'scenario' => $this->scenario,
        ], static fn ($value): bool => $value !== null);
    }
}
