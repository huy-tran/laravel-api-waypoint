<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Contracts;

/**
 * A named, app-declared piece of seedable state.
 *
 * POST /scenarios accepts a name from config('api-waypoint.scenarios') and this
 * scenario's own declared parameters, and nothing else. There is deliberately no
 * code path that accepts a class name, factory name or attribute array.
 */
interface WaypointScenario
{
    /** Human-readable description shown in the Central App. */
    public static function description(): string;

    /**
     * Parameter schema, the same JSON-Schema-ish shape as an endpoint input.
     * Return [] for none.
     *
     * Example:
     *   [
     *       'type' => 'object',
     *       'properties' => [
     *           'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 3],
     *       ],
     *       'required' => [],
     *   ]
     *
     * @return array<string, mixed>
     */
    public static function parameters(): array;

    /**
     * Create the state.
     *
     * Return an array of records shaped per the contract's scenario response:
     *   [['type' => 'order', 'key' => 12, 'label' => '#1001', 'model' => Order::class], ...]
     *
     * Implementations must be safe to call repeatedly. The runner wraps this in a
     * transaction and records the returned keys against a cleanup token.
     *
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public function run(array $parameters): array;
}
