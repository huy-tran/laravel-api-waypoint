<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Attributes;

use Attribute;

/**
 * Human-facing endpoint metadata the compiler cannot infer well.
 *
 * Without it the summary falls back to the Action class docblock's first line,
 * then to a humanised route name.
 *
 *   #[WaypointEndpoint(summary: 'Refund a paid order', module: 'billing')]
 *   class RefundOrder { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class WaypointEndpoint
{
    /**
     * @param string|null $summary One-line description shown in the Central App.
     * @param string|null $module Overrides module attribution, for actions that live
     *                            in one module but belong to another's surface.
     * @param bool $deprecated Surfaced so the Central App can strike the endpoint through.
     * @param array<int, string> $abilities Token abilities required, when middleware does not say.
     * @param array<int, string> $roles Waypoint roles that may call this endpoint.
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $module = null,
        public bool $deprecated = false,
        public array $abilities = [],
        public array $roles = [],
    ) {}
}
