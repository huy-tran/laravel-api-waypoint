<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Turns the router's route table into the candidate list.
 *
 * Emits one candidate per HTTP method, so a route registered for both PUT and
 * PATCH yields two endpoints. Ids are finalised by SchemaCompiler, which knows
 * the module an unnamed route belongs to.
 */
class RouteCollector
{
    /** HEAD is registered implicitly alongside GET; OPTIONS is CORS plumbing. */
    private const IGNORED_METHODS = ['HEAD', 'OPTIONS'];

    /** Canonical ordering, so a multi-method route always suffixes the same way. */
    private const METHOD_ORDER = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(protected Router $router) {}

    /**
     * @param array<string, mixed> $config The api-waypoint.routes config block.
     * @return array<int, CollectedRoute>
     */
    public function collect(array $config): array
    {
        $include = (array) ($config['include'] ?? ['api/*']);
        $exclude = (array) ($config['exclude'] ?? []);
        $required = array_values(array_filter((array) ($config['required_middleware'] ?? [])));

        $collected = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $this->shouldCollect($route, $include, $exclude, $required)) {
                continue;
            }

            $methods = $this->methodsFor($route);
            $suffix = count($methods) > 1;

            foreach ($methods as $method) {
                $collected[] = new CollectedRoute(
                    $route,
                    $method,
                    $this->baseId($route, $method, $suffix),
                    $this->hasUsableName($route),
                );
            }
        }

        return $collected;
    }

    /**
     * @param array<int, string> $include
     * @param array<int, string> $exclude
     * @param array<int, string> $required
     */
    protected function shouldCollect(Route $route, array $include, array $exclude, array $required): bool
    {
        $uri = $route->uri();

        if (! Str::is($include, $uri)) {
            return false;
        }

        if ($exclude !== [] && Str::is($exclude, $uri)) {
            return false;
        }

        if ($required === []) {
            return true;
        }

        $middleware = array_filter($route->gatherMiddleware(), 'is_string');

        foreach ($required as $needle) {
            foreach ($middleware as $applied) {
                // Match "auth" against "auth:sanctum" as well as against an exact name.
                if ($applied === $needle || str_starts_with($applied, $needle.':')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The HTTP methods this route contributes an endpoint for, in canonical order.
     *
     * Public because the snapshot middleware has to derive the same endpoint id the
     * compiler assigned, and deriving it twice from two implementations is how a
     * snapshot ends up filed against nothing.
     *
     * @return array<int, string>
     */
    public function methodsFor(Route $route): array
    {
        $methods = array_values(array_diff($route->methods(), self::IGNORED_METHODS));

        usort($methods, static function (string $a, string $b): int {
            $ai = array_search($a, self::METHOD_ORDER, true);
            $bi = array_search($b, self::METHOD_ORDER, true);

            return [$ai === false ? PHP_INT_MAX : $ai, $a] <=> [$bi === false ? PHP_INT_MAX : $bi, $b];
        });

        return $methods;
    }

    /**
     * Prefer the route name with the API prefix stripped: api.v1.orders.store
     * becomes orders.store. An unnamed route gets a URI-derived id, which the
     * compiler prefixes with the module and flags with an unnamed_route warning,
     * because a derived id moves whenever the URI is refactored and the Central App
     * keys saved requests on it.
     */
    public function baseId(Route $route, string $method, bool $suffixMethod): string
    {
        $id = $this->hasUsableName($route)
            ? $this->stripApiPrefix((string) $route->getName())
            : $this->deriveId($route, $method);

        return $suffixMethod ? $id.'.'.strtolower($method) : $id;
    }

    /**
     * A route declared inside ->name('api.v1.') but given no name of its own still
     * reports "api.v1." as its name. Treating that as named would hand several
     * unrelated routes the same id, so it counts as unnamed.
     */
    public function hasUsableName(Route $route): bool
    {
        $name = (string) $route->getName();

        return $name !== '' && ! str_ends_with($name, '.');
    }

    /**
     * Strips leading "api" and version segments: api.v1.orders.store -> orders.store.
     * Stops at the first segment that is neither, so an "api_keys" resource survives.
     */
    public function stripApiPrefix(string $name): string
    {
        $segments = explode('.', trim($name, '.'));

        while (count($segments) > 1) {
            $head = strtolower($segments[0]);

            if ($head === 'api' || preg_match('/^v\d+(_\d+)?$/', $head) === 1) {
                array_shift($segments);

                continue;
            }

            break;
        }

        return implode('.', $segments);
    }

    protected function deriveId(Route $route, string $method): string
    {
        $uri = preg_replace('/\{([^}?]+)\??\}/', '$1', $route->uri()) ?? $route->uri();
        $uri = (string) preg_replace('#^api/(v\d+/)?#', '', $uri);

        $slug = Str::slug(str_replace('/', ' ', $uri), '_');

        return strtolower($method).'.'.($slug === '' ? 'root' : $slug);
    }
}
