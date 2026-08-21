# API Waypoint

This application ships `hygo/laravel-api-waypoint`, a development-only package that compiles a machine-readable description of every API endpoint: input schema, query contract, auth requirements and payload-generation hints.

- The compiled document is the source of truth for endpoint shape. After changing a route, a Spatie Data class, or a query contract, run {{ $assist->artisanCommand('waypoint:check') }} and resolve what it reports.
- `waypoint:check` compiles with the HTTP surface switched off, so it works in CI and in any checkout. Never enable the surface just to run a check.
- Never enable this outside local. `API_WAYPOINT_ENABLED` stays false anywhere shared, and listing `production` in `api-waypoint.environments` is a deliberate hard boot failure, not a misconfiguration to work around.
- An endpoint the compiler cannot describe is still emitted with `"input": null` and listed under `diagnostics.unmapped_routes`. Fix it by giving the action a Data class or implementing `ProvidesWaypointInput`. Do not silence it by excluding the route.
- `routes.include` must match the URI prefix this application actually registers endpoints under. The shipped default is `api/*`, which matches nothing in an application that routes per module or per file.
@if($assist->hasMcpEnabled())
- Read the schema with the `waypoint-check`, `waypoint-endpoints` and `waypoint-endpoint` MCP tools rather than opening the compiled JSON. They compile on every call, so they never answer from before your edit, and they need neither the HTTP surface nor the shared secret.
@endif
@if($assist->hasSkillsEnabled())
- The `api-waypoint` skill covers setup, the HTTP surface, and how to resolve each unmapped reason.
@endif
