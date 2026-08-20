<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Contracts;

use Hygo\ApiWaypoint\Compiler\Query\QueryConfig;
use Hygo\ApiWaypoint\Concerns\HasWaypointQuery;

/**
 * Declares an endpoint's Spatie Query Builder contract.
 *
 * Spatie's allowed lists are built inside a runtime method chain, so they are
 * invisible to reflection. Declaring them once as a QueryConfig and building the
 * query from the same object keeps a single source of truth.
 *
 * @see HasWaypointQuery
 */
interface ProvidesWaypointQuery
{
    public static function waypointQuery(): QueryConfig;
}
