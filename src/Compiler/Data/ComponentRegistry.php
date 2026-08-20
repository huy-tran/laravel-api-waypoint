<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Data;

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;

/**
 * The $ref registry and the cycle guard.
 *
 * A single Data class is reused across endpoints, so it is compiled once and
 * referenced thereafter. That is also the performance story: on an app with 200
 * Data classes, hitting the registry before compiling is the difference between
 * one compile per class and one per referencing endpoint.
 *
 * The cycle guard exists because a tree-shaped Data class (a category with
 * children of its own type) would otherwise recurse until the stack gives out.
 */
class ComponentRegistry
{
    /** @var array<string, array<string, mixed>> Component name => JSON Schema. */
    protected array $components = [];

    /** @var array<class-string, string> FQCN => component name. */
    protected array $names = [];

    /** @var array<class-string, true> Classes part-way through compilation. */
    protected array $inProgress = [];

    /** @var array<class-string, true> Classes that closed a cycle. */
    protected array $recursive = [];

    /**
     * Component keys are Module.ClassName rather than FQCNs, to keep JSON Pointers
     * clean. The FQCN is carried in x-laravel.class.
     */
    public function nameFor(string $class, string $module): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }

        $basename = ($position = strrpos($class, '\\')) === false
            ? $class
            : substr($class, $position + 1);

        $name = $module.'.'.$basename;

        // Two classes with the same basename in the same module: disambiguate with
        // the enclosing namespace segment rather than letting one overwrite the other.
        if (isset($this->components[$name]) || in_array($name, $this->names, true)) {
            $segments = explode('\\', $class);
            $parent = count($segments) >= 2 ? $segments[count($segments) - 2] : 'Alt';
            $name = $module.'.'.$parent.$basename;

            $suffix = 2;
            while (isset($this->components[$name]) || in_array($name, $this->names, true)) {
                $name = $module.'.'.$parent.$basename.$suffix++;
            }
        }

        return $this->names[$class] = $name;
    }

    public function knows(string $class): bool
    {
        return isset($this->names[$class]) && isset($this->components[$this->names[$class]]);
    }

    public function refForClass(string $class): ?string
    {
        return isset($this->names[$class])
            ? SchemaDocument::refFor($this->names[$class])
            : null;
    }

    public function ref(string $component): string
    {
        return SchemaDocument::refFor($component);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function register(string $component, array $schema): void
    {
        $this->components[$component] = $schema;
    }

    public function beginCompiling(string $class): void
    {
        $this->inProgress[$class] = true;
    }

    public function finishCompiling(string $class): void
    {
        unset($this->inProgress[$class]);
    }

    public function isCompiling(string $class): bool
    {
        return isset($this->inProgress[$class]);
    }

    public function markRecursive(string $class): void
    {
        $this->recursive[$class] = true;
    }

    public function isRecursive(string $class): bool
    {
        return isset($this->recursive[$class]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        ksort($this->components);

        return $this->components;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $component): ?array
    {
        return $this->components[$component] ?? null;
    }

    public function count(): int
    {
        return count($this->components);
    }
}
