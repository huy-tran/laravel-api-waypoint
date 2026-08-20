<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Support;

use Hygo\ApiWaypoint\Contracts\WaypointScenario;
use InvalidArgumentException;

/**
 * The declared scenario list.
 *
 * A scenario is reachable only if its name appears in config. There is
 * deliberately no path from a request body to a class name, a factory or an
 * attribute array: the HTTP surface can invoke what the app declared and nothing
 * else.
 */
class ScenarioRegistry
{
    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = array_keys($this->configured());
        sort($names);

        return $names;
    }

    public function has(string $name): bool
    {
        return isset($this->configured()[$name]);
    }

    public function resolve(string $name): WaypointScenario
    {
        $configured = $this->configured();

        if (! isset($configured[$name])) {
            throw new InvalidArgumentException("Unknown scenario [{$name}].");
        }

        $class = $configured[$name];

        if (! class_exists($class) || ! is_a($class, WaypointScenario::class, true)) {
            throw new InvalidArgumentException(
                "Scenario [{$name}] maps to [{$class}], which does not implement WaypointScenario."
            );
        }

        return app($class);
    }

    /**
     * The list the Central App renders its Setup tab from.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->configured() as $name => $class) {
            if (! class_exists($class) || ! is_a($class, WaypointScenario::class, true)) {
                continue;
            }

            $described[] = [
                'name' => $name,
                'description' => $class::description(),
                'parameters' => $class::parameters() ?: [
                    'type' => 'object',
                    'required' => [],
                    'properties' => (object) [],
                ],
            ];
        }

        usort($described, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $described;
    }

    /**
     * Whatever the application put in config, unvalidated.
     *
     * Deliberately not annotated as class-string<WaypointScenario>: that would be
     * asserting the very thing resolve() has to check, and static analysis would
     * then declare the check redundant. Config is input.
     *
     * @return array<string, string>
     */
    protected function configured(): array
    {
        $scenarios = [];

        foreach ((array) config('api-waypoint.scenarios', []) as $name => $class) {
            if (is_string($name) && is_string($class)) {
                $scenarios[$name] = $class;
            }
        }

        return $scenarios;
    }
}
