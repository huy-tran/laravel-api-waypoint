<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use Illuminate\Routing\Route;
use ReflectionClass;
use Throwable;

/**
 * Resolves a route's "uses" to a class, a method and a type.
 *
 * Reflection is memoised per class rather than per route: a resource controller
 * contributes seven routes and reflecting it seven times is most of the
 * difference between a fast compile and a slow one.
 */
class ActionResolver
{
    private const AS_CONTROLLER_TRAIT = 'Lorisleiva\Actions\Concerns\AsController';

    /** @var array<string, ReflectionClass<object>|false> */
    protected array $reflections = [];

    /** @var array<string, bool> */
    protected array $asController = [];

    public function resolve(Route $route): ResolvedAction
    {
        $uses = $route->getAction('uses');

        if (! is_string($uses) || $uses === '' || $route->getActionName() === 'Closure') {
            return new ResolvedAction(null, null, ResolvedAction::TYPE_CLOSURE, false);
        }

        [$class, $method] = $this->split($uses);

        if ($class === null || ! class_exists($class)) {
            return new ResolvedAction(
                $class,
                $method,
                ResolvedAction::TYPE_CONTROLLER,
                false,
            );
        }

        $reflection = $this->reflect($class);

        if ($reflection === null) {
            return new ResolvedAction($class, $method, ResolvedAction::TYPE_CONTROLLER, false);
        }

        $asController = $this->usesAsControllerTrait($reflection);

        return new ResolvedAction(
            $class,
            $method,
            $asController ? ResolvedAction::TYPE_LARAVEL_ACTIONS : ResolvedAction::TYPE_CONTROLLER,
            $asController,
            $reflection,
        );
    }

    /**
     * @return array{0: class-string|null, 1: string|null}
     */
    protected function split(string $uses): array
    {
        if (str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);

            /** @var class-string $class */
            return [$class, $method];
        }

        /** @var class-string $uses */
        return [$uses, '__invoke'];
    }

    /**
     * Reflect a class name, or return null if it cannot be reflected.
     *
     * The parameter is a plain string, not a class-string: this is a public entry
     * point taking a name read out of the route table, and pretending the name is
     * already known-good is exactly what would make the catch below dead code.
     *
     * @return ReflectionClass<object>|null
     */
    public function reflect(string $class): ?ReflectionClass
    {
        if (array_key_exists($class, $this->reflections)) {
            return $this->reflections[$class] ?: null;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            $this->reflections[$class] = false;

            return null;
        }

        $this->reflections[$class] = $reflection;

        return $reflection;
    }

    /**
     * laravel-actions is a soft dependency at compile time: an app with plain
     * controllers still compiles, every endpoint simply reports type "controller".
     *
     * @param ReflectionClass<object> $reflection
     */
    protected function usesAsControllerTrait(ReflectionClass $reflection): bool
    {
        $name = $reflection->getName();

        if (isset($this->asController[$name])) {
            return $this->asController[$name];
        }

        if (! trait_exists(self::AS_CONTROLLER_TRAIT)) {
            return $this->asController[$name] = false;
        }

        return $this->asController[$name] = in_array(
            self::AS_CONTROLLER_TRAIT,
            $this->allTraits($reflection),
            true
        );
    }

    /**
     * Traits used anywhere in the hierarchy, including traits used by traits.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<int, string>
     */
    protected function allTraits(ReflectionClass $reflection): array
    {
        $traits = [];

        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getTraitNames() as $trait) {
                $traits[$trait] = true;

                foreach ($this->nestedTraits($trait) as $nested) {
                    $traits[$nested] = true;
                }
            }
        }

        return array_keys($traits);
    }

    /**
     * @return array<int, string>
     */
    protected function nestedTraits(string $trait): array
    {
        $found = [];

        try {
            $reflection = new ReflectionClass($trait);
        } catch (Throwable) {
            return $found;
        }

        foreach ($reflection->getTraitNames() as $nested) {
            $found[] = $nested;
            $found = array_merge($found, $this->nestedTraits($nested));
        }

        return $found;
    }
}
