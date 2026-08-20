<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Recording;

use Closure;
use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\RouteCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in middleware that records a sanitised example of a real response.
 *
 * This is the honest alternative to deriving a body schema from a Fractal
 * transformer: rather than guess the shape, capture one. Add it to the API
 * middleware stack in local development only.
 *
 * Writes at most one snapshot per endpoint per TTL window, so a busy dev session
 * does not turn into thousands of file writes.
 */
class RecordsWaypointResponses
{
    public function __construct(protected SnapshotStore $snapshots) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (Throwable) {
            // Recording is a developer convenience and must never affect the response
            // the application is in the middle of returning.
        }

        return $response;
    }

    protected function record(Request $request, Response $response): void
    {
        if (! (bool) config('api-waypoint.snapshots.enabled', false)) {
            return;
        }

        if ($response->getStatusCode() >= 300 || ! $response instanceof JsonResponse) {
            return;
        }

        $endpointId = $this->endpointId($request);

        if ($endpointId === null || ! $this->snapshots->isStale($endpointId)) {
            return;
        }

        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return;
        }

        $this->snapshots->put($endpointId, $body, now()->toIso8601String());
    }

    /**
     * Must match the id the compiler assigns, or the snapshot attaches to nothing.
     *
     * Derived through the collector itself rather than reimplemented here, because
     * two implementations of the same rule drift and the failure is silent.
     */
    protected function endpointId(Request $request): ?string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $collector = new RouteCollector(app('router'));

        // An unnamed route's id is prefixed with its module, which needs the action
        // resolved. Rather than half-derive it, skip the snapshot: misfiling one is
        // worse than not having it.
        if (! $collector->hasUsableName($route)) {
            return null;
        }

        return $collector->baseId(
            $route,
            $request->method(),
            count($collector->methodsFor($route)) > 1,
        );
    }
}
