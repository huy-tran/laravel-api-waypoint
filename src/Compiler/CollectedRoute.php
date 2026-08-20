<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use Illuminate\Routing\Route;

/**
 * One (route, HTTP method) pair: the unit the rest of the pipeline works on.
 *
 * A route registered for both PUT and PATCH produces two of these, because the
 * Central App keys saved requests on the endpoint id and the two are separate
 * things a developer can send.
 *
 * The id is assigned by SchemaCompiler rather than here, because an unnamed
 * route's id is prefixed with its module, which is only known once the action has
 * been resolved.
 */
class CollectedRoute
{
    public string $id = '';

    public function __construct(
        public readonly Route $route,
        public readonly string $method,
        public readonly string $baseId,
        public readonly bool $named,
    ) {}

    public function uri(): string
    {
        return '/'.ltrim($this->route->uri(), '/');
    }

    /**
     * The route's own name, or null. A group's ->name('api.v1.') prefix leaks onto
     * unnamed routes inside it, and that is not a name.
     */
    public function name(): ?string
    {
        $name = (string) $this->route->getName();

        return $name === '' || str_ends_with($name, '.') ? null : $name;
    }

    /** Methods that carry a request body, and so can have an input schema. */
    public function carriesBody(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * @return array<int, string>
     */
    public function middleware(): array
    {
        $middleware = array_filter($this->route->gatherMiddleware(), 'is_string');

        return array_values(array_unique($middleware));
    }
}
