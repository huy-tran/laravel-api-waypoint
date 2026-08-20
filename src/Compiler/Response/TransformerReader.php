<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Response;

use ReflectionClass;
use Throwable;

/**
 * Reads the include lists off a Fractal transformer.
 *
 * v1 deliberately does not derive a response body schema from a transformer: a
 * transform() method is arbitrary PHP, and a guessed shape is worse than an
 * honest "opaque" plus a real snapshot.
 */
class TransformerReader
{
    /** @var array<string, array{available: array<int, string>, default: array<int, string>}> */
    protected array $memo = [];

    /**
     * @return array{available: array<int, string>, default: array<int, string>}
     */
    public function includes(?string $transformer): array
    {
        if ($transformer === null || ! class_exists($transformer)) {
            return ['available' => [], 'default' => []];
        }

        if (isset($this->memo[$transformer])) {
            return $this->memo[$transformer];
        }

        try {
            $reflection = new ReflectionClass($transformer);

            return $this->memo[$transformer] = [
                'available' => $this->readList($reflection, 'availableIncludes'),
                'default' => $this->readList($reflection, 'defaultIncludes'),
            ];
        } catch (Throwable) {
            return $this->memo[$transformer] = ['available' => [], 'default' => []];
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<int, string>
     */
    protected function readList(ReflectionClass $reflection, string $property): array
    {
        while (! $reflection->hasProperty($property)) {
            $parent = $reflection->getParentClass();

            if ($parent === false) {
                return [];
            }

            $reflection = $parent;
        }

        $defaults = $reflection->getDefaultProperties();
        $value = $defaults[$property] ?? [];

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
