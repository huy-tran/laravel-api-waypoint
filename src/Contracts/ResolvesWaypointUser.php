<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Produces the dedicated user a waypoint token is minted for.
 *
 * The email is supplied by the package, derived from
 * config('api-waypoint.tokens.email_pattern'), and the controller re-checks the
 * returned user's email against it. That check is the reason a real customer
 * account can never be impersonated: even a resolver that goes looking for one
 * cannot get a token issued for it.
 */
interface ResolvesWaypointUser
{
    /**
     * Find or create the waypoint user for this role.
     *
     * The returned user's email address must equal $email.
     */
    public function resolve(string $email, string $role): Authenticatable;
}
