<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * Attributes an action class to a module.
 *
 * nwidart/laravel-modules is a soft dependency: detected with class_exists() and
 * skipped entirely when absent, so the package installs and works in an app that
 * has no module system at all.
 */
class ModuleResolver
{
    /** @var array<string, array{key: string, name: string}> */
    protected array $memo = [];

    /** @var array<string, string>|null Namespace prefix => module name. */
    protected ?array $registered = null;

    public function __construct(protected string $default = 'app') {}

    /**
     * @return array{key: string, name: string}
     */
    public function resolve(?string $class): array
    {
        if ($class === null || $class === '') {
            return $this->fallback();
        }

        return $this->memo[$class] ??= $this->resolveFresh($class);
    }

    /**
     * @return array{key: string, name: string}
     */
    protected function resolveFresh(string $class): array
    {
        if (($module = $this->fromRegisteredModules($class)) !== null) {
            return $module;
        }

        if (($module = $this->fromNamespace($class)) !== null) {
            return $module;
        }

        return $this->fallback();
    }

    /**
     * Resolver 1: match the class namespace against nwidart's registered modules.
     *
     * @return array{key: string, name: string}|null
     */
    protected function fromRegisteredModules(string $class): ?array
    {
        foreach ($this->registeredModules() as $namespace => $name) {
            if (str_starts_with($class, $namespace)) {
                return ['key' => Str::snake($name), 'name' => $name];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function registeredModules(): array
    {
        if ($this->registered !== null) {
            return $this->registered;
        }

        $this->registered = [];

        if (! class_exists(Module::class)) {
            return $this->registered;
        }

        try {
            /** @var array<string, object> $modules */
            $modules = Module::all();

            foreach ($modules as $module) {
                if (! method_exists($module, 'getName')) {
                    continue;
                }

                $name = (string) $module->getName();
                $namespace = rtrim((string) config('modules.namespace', 'Modules'), '\\').'\\'.$name.'\\';

                $this->registered[$namespace] = $name;
            }
        } catch (Throwable) {
            // A misconfigured module system must not take the compiler down with it.
            $this->registered = [];
        }

        // Longest namespace first, so Modules\OrdersExport does not swallow Modules\Orders.
        uksort($this->registered, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->registered;
    }

    /**
     * Resolver 2: the second namespace segment when the first is "Modules".
     *
     * @return array{key: string, name: string}|null
     */
    protected function fromNamespace(string $class): ?array
    {
        $segments = explode('\\', ltrim($class, '\\'));

        if (count($segments) < 2 || $segments[0] !== 'Modules') {
            return null;
        }

        return ['key' => Str::snake($segments[1]), 'name' => $segments[1]];
    }

    /**
     * @return array{key: string, name: string}
     */
    protected function fallback(): array
    {
        return ['key' => Str::snake($this->default), 'name' => Str::studly($this->default)];
    }
}
