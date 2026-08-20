<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compares X-Api-Waypoint-Secret against the configured secret.
 *
 * A mismatch aborts with 404 and Laravel's standard not-found body, never 403.
 * A 403 would confirm the route exists, which is exactly the fact worth hiding.
 */
class VerifyWaypointSecret
{
    public const HEADER = 'X-Api-Waypoint-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('api-waypoint.secret', '');
        $presented = (string) ($request->header(self::HEADER) ?? $request->bearerToken() ?? '');

        if ($configured === '' || ! $this->matches($configured, $presented)) {
            // The contract fixes this body. It must never say "forbidden", or
            // anything else that confirms the route exists.
            abort(404, 'Not Found.');
        }

        return $next($request);
    }

    /**
     * hash_equals() requires equal-length arguments to be constant-time, and
     * returns false immediately otherwise. Hashing both sides first makes the
     * comparison constant-time for any presented length, so a secret differing
     * only in length is not distinguishable by timing.
     */
    protected function matches(string $configured, string $presented): bool
    {
        if ($presented === '') {
            return false;
        }

        return hash_equals(hash('sha256', $configured), hash('sha256', $presented));
    }
}
