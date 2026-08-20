<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;

/**
 * One link in the input-resolution chain.
 *
 * Returning null means "not my case, ask the next one". The chain is a container
 * binding, so an app or a future InlineValidateResolver can be appended without
 * touching the compiler.
 */
interface InputResolverContract
{
    public function resolve(CollectedRoute $route, ResolvedAction $action): ?InputResolution;
}
