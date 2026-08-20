<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Contracts;

/**
 * Declares an endpoint's response shape when it cannot be inferred.
 *
 * The compiler never guesses a transformer; either the attribute or this contract
 * supplies it.
 */
interface ProvidesWaypointResponse
{
    /**
     * FQCN of the Fractal transformer used by this endpoint, or null.
     *
     * @return class-string|null
     */
    public static function waypointTransformer(): ?string;

    /**
     * HTTP status returned on success, or null to fall back to inference.
     */
    public static function waypointSuccessStatus(): ?int;
}
