<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input\Resolvers;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Input\InputResolution;
use Hygo\ApiWaypoint\Compiler\Input\InputResolverContract;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Spatie\LaravelData\Contracts\BaseData;
use Throwable;

/**
 * Resolver 2: the first Data-typed parameter on the action's entry point.
 *
 * asController() is checked before handle() because that is where an Action puts
 * its HTTP-specific signature when the two differ, and the HTTP signature is the
 * one describing the request body.
 *
 * Models (route bindings), Request and scalars are ignored: none of them is the
 * body.
 */
class HandleParameterResolver implements InputResolverContract
{
    /** Entry points, in the order they describe the HTTP surface. */
    private const CANDIDATES = ['asController', 'handle', '__invoke'];

    public function resolve(CollectedRoute $route, ResolvedAction $action): ?InputResolution
    {
        if (! $action->isReflectable() || $action->reflection === null) {
            return null;
        }

        $methods = $action->method !== null
            ? array_values(array_unique([$action->method, ...self::CANDIDATES]))
            : self::CANDIDATES;

        foreach ($methods as $name) {
            if (! $action->reflection->hasMethod($name)) {
                continue;
            }

            $dataClass = $this->firstDataParameter($action->reflection->getMethod($name));

            if ($dataClass !== null) {
                return InputResolution::mapped($dataClass);
            }
        }

        return null;
    }

    protected function firstDataParameter(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            foreach ($this->namedTypes($type) as $named) {
                if ($named->isBuiltin()) {
                    continue;
                }

                $class = $named->getName();

                if ($this->isDataClass($class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, ReflectionNamedType>
     */
    protected function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_values(array_filter(
                $type->getTypes(),
                static fn ($member): bool => $member instanceof ReflectionNamedType
            ));
        }

        return [];
    }

    protected function isDataClass(string $class): bool
    {
        try {
            return class_exists($class) && is_a($class, BaseData::class, true);
        } catch (Throwable) {
            return false;
        }
    }
}
