# Changelog

All notable changes to `hygo/laravel-api-waypoint` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file is the release pipeline's source of truth. `.github/workflows/release.yml`
refuses to cut a tag for a version that has no section here, and publishes that
section as the GitHub Release notes. See [Releasing](#releasing) below.

The **wire format** is versioned separately from the package, as
`schema_format_version` in the document. A breaking change to the wire format is a
new format version, not merely a new package major.

## [Unreleased]

### Added

- `waypoint:install`: publishes the config, detects which URI prefix the host
  application registers its API under and writes `routes.include` to match, then
  adds `API_WAYPOINT_ENABLED` and a generated `API_WAYPOINT_SECRET` to `.env`,
  documenting both in `.env.example` disabled and blank. Refuses to run in
  production, never overwrites a secret that is already set, and reports a
  customised `routes.include` rather than overwriting it. `--include=`,
  `--secret=`, `--skip-env` and `--force` override the individual steps.
- Laravel Boost integration. `resources/boost/guidelines/core.blade.php` and the
  `api-waypoint` skill under `resources/boost/skills/` are discovered by
  `boost:install` and composed into whichever agent files a project uses. The
  skill covers setup, the HTTP surface, the 404 ambiguity and its `route:list`
  remedy, the four unmapped reasons, query contracts and the attributes.
- Three read-only MCP tools, surfaced through Boost's server by appending to
  `boost.mcp.tools.include`: `waypoint-check` (gaps and warnings, each with a
  remedy), `waypoint-endpoints` (the index, filterable by module, substring or
  unmapped) and `waypoint-endpoint` (one endpoint with every referenced Data
  class resolved transitively). They read the compiler rather than the HTTP
  surface, so they need neither the secret nor `enabled`, and they recompile per
  call so they cannot answer from before an agent's edit. Guarded on
  `laravel/mcp` being installed.

## [0.1.0] - 2026-08-20

First release. The compiler and the full HTTP surface are complete and covered by
383 tests, but the package has not yet been run against a production-sized
application, which is why this is 0.1.0 rather than 1.0.0.

Requires Laravel 12 or 13. Laravel 11 is not supported: its security-fix window
closed in March 2026 and every 11.x release now carries an unpatched advisory, so
Composer declines to install it at all.

### Added

- Schema compiler: routes, modules, actions, Spatie Data input schemas, Spatie
  Query Builder contracts, Fractal response descriptions and payload-generation
  hints, compiled into one document (wire format `1.0`).
- Dev-only HTTP surface: schema, manifest, reference lookups, scenarios and
  short-lived role tokens, registered only when explicitly enabled in a permitted
  environment with a secret set.
- `waypoint:schema`, `waypoint:check` and `waypoint:snapshot` Artisan commands.
  `waypoint:check --baseline` fails CI when an endpoint or DTO hash moves without
  the committed baseline being regenerated.
- `ProvidesWaypointInput`, `ProvidesWaypointQuery`, `ProvidesWaypointResponse`,
  `WaypointScenario` and `ResolvesWaypointUser` contracts, plus the
  `HasWaypointQuery` trait that builds the query from the declared `QueryConfig`.
- `resources/schema/api-waypoint-1.0.json`, a meta-schema for the wire format that
  both this package and the Central App test against.
- Opt-in response snapshot recording, sanitised with a recursive deny-list.

## Releasing

Releases are cut by the **Release** workflow, never by tagging locally.

1. Move the entries you are shipping out of `## [Unreleased]` into a new
   `## [x.y.z] - YYYY-MM-DD` section.
2. Commit that to `master`.
3. Run the **Release** workflow from the Actions tab, entering `x.y.z`.

The workflow refuses to proceed unless the version is valid semver, has a matching
section in this file, and does not already have a tag. It then runs the full check
suite, creates the annotated `vx.y.z` tag, and opens a GitHub Release using that
section as the notes. Packagist picks the tag up from there.

[Unreleased]: https://github.com/huy-tran/laravel-api-waypoint/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/huy-tran/laravel-api-waypoint/releases/tag/v0.1.0
