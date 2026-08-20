<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One audit line per waypoint request.
 *
 * Cheap, and it makes "who seeded 400 orders" answerable. The actor is a
 * fingerprint of the presented secret, never the secret itself.
 */
class LogWaypointRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        Log::channel((string) config('api-waypoint.log_channel', 'stack'))->info('api-waypoint', array_filter([
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'actor' => $this->fingerprint($request),
            'ip' => $request->ip(),
            'scenario' => $request->input('scenario'),
            'role' => $request->input('role'),
            'table' => $request->route('table'),
            'column' => $request->route('column'),
        ], static fn ($value): bool => $value !== null));

        return $response;
    }

    protected function fingerprint(Request $request): string
    {
        $presented = (string) ($request->header(VerifyWaypointSecret::HEADER) ?? '');

        return $presented === ''
            ? 'anonymous'
            : substr(hash('sha256', $presented), 0, 8);
    }
}
