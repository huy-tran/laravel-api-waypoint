<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;

/**
 * Runs the resolver chain in priority order, first non-null answer wins.
 */
class InputResolver
{
    /**
     * @param array<int, InputResolverContract> $resolvers
     */
    public function __construct(protected array $resolvers = []) {}

    public function push(InputResolverContract $resolver): self
    {
        $this->resolvers[] = $resolver;

        return $this;
    }

    /** Insert ahead of everything, for a host app overriding the built-in chain. */
    public function prepend(InputResolverContract $resolver): self
    {
        array_unshift($this->resolvers, $resolver);

        return $this;
    }

    public function resolve(CollectedRoute $route, ResolvedAction $action): InputResolution
    {
        foreach ($this->resolvers as $resolver) {
            $resolution = $resolver->resolve($route, $action);

            if ($resolution !== null) {
                return $resolution;
            }
        }

        // Only reachable if the terminal resolver has been removed from the chain.
        return InputResolution::unmapped(UnmappedReason::NO_DATA_CLASS);
    }

    /**
     * @return array<int, InputResolverContract>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }
}
