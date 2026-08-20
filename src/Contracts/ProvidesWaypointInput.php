<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Contracts;

/**
 * Escape hatch for endpoints whose request body the reflection resolver cannot see.
 *
 * Implementing this on an Action always wins over reflection, so it is also the
 * way to correct a wrong guess.
 */
interface ProvidesWaypointInput
{
    /**
     * FQCN of the Spatie Data class describing this endpoint's request body,
     * or null when the endpoint takes no body.
     *
     * @return class-string|null
     */
    public static function waypointInput(): ?string;
}
