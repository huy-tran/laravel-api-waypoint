<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input\Resolvers;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Input\InputResolution;
use Hygo\ApiWaypoint\Compiler\Input\InputResolverContract;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Hygo\ApiWaypoint\Contracts\ProvidesWaypointInput;
use Throwable;

/**
 * Resolver 1: the ProvidesWaypointInput contract.
 *
 * Highest priority, so it is both the escape hatch for bodies reflection cannot
 * see and the way to correct a wrong guess.
 */
class ContractInputResolver implements InputResolverContract
{
    public function resolve(CollectedRoute $route, ResolvedAction $action): ?InputResolution
    {
        $class = $action->class;

        if ($class === null || ! is_a($class, ProvidesWaypointInput::class, true)) {
            return null;
        }

        try {
            /** @var class-string<ProvidesWaypointInput> $class */
            $declared = $class::waypointInput();
        } catch (Throwable $exception) {
            return InputResolution::unmapped(
                UnmappedReason::UNSUPPORTED_ACTION,
                sprintf('%s::waypointInput() threw: %s', $class, $exception->getMessage())
            );
        }

        // Returning null is a positive statement that the endpoint takes no body,
        // which is a complete answer and must not be reported as a gap.
        if ($declared === null) {
            return InputResolution::none();
        }

        if (! class_exists($declared)) {
            return InputResolution::unmapped(
                UnmappedReason::NO_DATA_CLASS,
                sprintf('%s::waypointInput() returned [%s], which does not exist.', $class, $declared)
            );
        }

        return InputResolution::mapped($declared);
    }
}
