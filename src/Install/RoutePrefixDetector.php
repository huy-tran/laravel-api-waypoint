<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Install;

use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Works out what routes.include should be for the host application.
 *
 * The shipped default is api/*, which is right for an application routing through
 * routes/api.php and finds nothing whatsoever in one that does not. A host
 * registering v1/orders from a per-module route file has no route under api/, so
 * the compiler describes zero endpoints — and that failure reads as "the package
 * does not work" rather than as one wrong line of config.
 */
class RoutePrefixDetector
{
    /**
     * URI prefixes that are never the application's own API surface.
     *
     * Framework and first-party tooling only. A deny list of *application* prefixes
     * would be a guess about someone else's naming, so anything not recognised here
     * is judged by its shape instead.
     */
    private const NEVER_API = [
        '_debugbar',
        '_ignition',
        'broadcasting',
        'horizon',
        'livewire',
        'nova-api',
        'pulse',
        'sanctum',
        'storage',
        'telescope',
        'up',
    ];

    public function __construct(protected Router $router) {}

    /**
     * Candidate include patterns, most-matched first.
     *
     * @return array<int, array{pattern: string, routes: int}>
     */
    public function candidates(): array
    {
        $counts = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $pattern = $this->patternFor($route->uri());

            if ($pattern === null) {
                continue;
            }

            $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
        }

        $candidates = [];

        foreach ($counts as $pattern => $routes) {
            $candidates[] = ['pattern' => $pattern, 'routes' => $routes];
        }

        // Descending by match count, then alphabetical, so v1/* and v2/* keep a
        // stable order in an application that versions both.
        usort($candidates, static fn (array $a, array $b): int => [$b['routes'], $a['pattern']] <=> [$a['routes'], $b['pattern']]);

        return $candidates;
    }

    /**
     * The patterns to write, best first.
     *
     * An api/ host gets api/*, which covers every version it will ever add. An
     * unprefixed host gets one pattern per version it actually serves: v1/* and
     * v2/* are the same API, and writing only the busier of the two would silently
     * describe half the application.
     *
     * @return array<int, string>
     */
    public function propose(): array
    {
        $candidates = $this->candidates();
        $best = $candidates[0]['pattern'] ?? null;

        if ($best === null) {
            return [];
        }

        if ($best === 'api/*') {
            return ['api/*'];
        }

        $versioned = array_values(array_filter(
            array_column($candidates, 'pattern'),
            static fn (string $pattern): bool => $pattern !== 'api/*'
        ));

        sort($versioned);

        return $versioned;
    }

    /**
     * How many routes the given patterns would make candidates.
     *
     * Reported by waypoint:install so the number is visible before the config is
     * written, rather than discovered later as an empty document.
     *
     * @param array<int, string> $patterns
     */
    public function matches(array $patterns): int
    {
        if ($patterns === []) {
            return 0;
        }

        $matched = 0;

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! $this->isWaypointRoute($uri) && Str::is($patterns, $uri)) {
                $matched++;
            }
        }

        return $matched;
    }

    protected function patternFor(string $uri): ?string
    {
        // Counting the package's own endpoints would let waypoint's prefix nominate
        // itself. The shipped prefix is unversioned and could never be proposed, but
        // a host is free to pin API_WAYPOINT_PREFIX to a versioned one, and seven
        // routes under v1/api-waypoint would propose v1/*.
        if ($this->isWaypointRoute($uri)) {
            return null;
        }

        $head = strtolower(explode('/', trim($uri, '/'))[0]);

        if ($head === '' || in_array($head, self::NEVER_API, true)) {
            return null;
        }

        if ($head === 'api') {
            return 'api/*';
        }

        // A leading version segment is the per-file / per-module convention:
        // Route::prefix('v1') with no api/ in front of it.
        return preg_match('/^v\d+(_\d+)?$/', $head) === 1 ? $head.'/*' : null;
    }

    protected function isWaypointRoute(string $uri): bool
    {
        $prefix = trim((string) config('api-waypoint.prefix', ''), '/');

        return $prefix !== '' && Str::is([$prefix, $prefix.'/*'], trim($uri, '/'));
    }
}
