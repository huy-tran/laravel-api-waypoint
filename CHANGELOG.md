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

- `waypoint:handshake`: prints what a local companion app needs to connect - base
  URL, header name, secret, and every path, so the consumer hardcodes none of
  them. `--json` for machine consumption.

  This is the answer to "why does reading the schema need a secret at all". The
  secret stays mandatory on every route: the document maps table and column names,
  action classes, roles, abilities and the URL of the token-minting endpoint, and
  requiring a custom header is also what stops a page your browser loaded from
  reaching the surface, since it cannot send one cross-origin without clearing a
  CORS preflight. What was actually costing something was the copy-paste, and a
  companion app that runs on the same machine can read the secret from the project
  directory instead. Being able to run the command in a checkout is a stronger
  claim to be the local dev tool than any credential presented over HTTP.

  It also reports what the HTTP surface deliberately cannot. A 404 is identical
  for an unregistered surface and a wrong secret; the payload carries
  `registered` plus an `unregistered_reason` of `disabled`,
  `environment_not_permitted`, `no_secret` or `not_loaded`, and the command exits
  non-zero, so a consumer can name the condition to fix. Refuses to run in
  production, and prints no secret there.

### Removed

- `api-waypoint-contract.md` and `specs.md`. Both had drifted into being wrong:
  the spec still said "ready to build", still listed Laravel 11 as supported after
  it was dropped, and still gave "no MCP server" as a non-goal two releases after
  the MCP tools shipped; the contract named an Artisan command, `api:schema`, that
  has never existed. `resources/schema/api-waypoint-1.0.json` is now the only
  statement of the wire format, and the conformance test validates the compiled
  document against it, so it cannot go stale without something failing. Both files
  remain in git history. The contract was the one of the two that shipped in the
  dist archive, so the published package loses it and gains nothing else.

## [0.3.0] - 2026-08-21

Moves the dev-only HTTP surface out of the host application's API version
namespace. Minor rather than patch: the default path changes, though only for an
application that had not published the config. The wire format is unchanged at
`1.0` and no contract, command or attribute moved. 455 tests.

### Changed

- The default route prefix is now `_api-waypoint`, was `v1/api-waypoint`. A version
  segment describes the host application's own REST API and the compatibility
  promises attached to it; a dev-only tool has none of those, and sitting in `v1/`
  both borrows a meaning it does not have and can collide with a real `v1` route.
  The leading underscore is the convention first-party dev tooling already uses -
  Boost serves `_boost/browser-logs`, and `_debugbar` and `_ignition` were already
  in this package's own `routes.exclude`.

  An application that published `config/api-waypoint.php` keeps whatever prefix it
  published: the published value wins over the package default, so nothing moves
  until that line is changed. An application relying on the package default gets a
  document at `/_api-waypoint` instead of `/v1/api-waypoint`, so point the Central
  App at the new path, or set `API_WAYPOINT_PREFIX=v1/api-waypoint` to keep the old
  one. The default `routes.exclude` patterns moved with it.

  `waypoint:install`, `RoutePrefixDetector` and the compiler's token-minting hint
  all read the configured prefix, so they follow automatically. The detector's
  guard against nominating waypoint's own prefix is now only reachable by a host
  that pins a versioned prefix, and its two tests pin one to keep testing it.

## [0.2.1] - 2026-08-21

Corrects the shipped Boost skill. Nothing in the compiler, the HTTP surface or the
MCP tools changed. Worth taking if you ran `boost:install` against 0.2.0: rerun it
to recompose the agent files. 453 tests.

### Fixed

- The skill shipped three `{{ $assist->artisanCommand(...) }}` calls inside
  `@boostsnippet` blocks, which reach an installed project as literal template
  text. Boost stashes each snippet body behind a placeholder, renders Blade, then
  restores the body verbatim, so a snippet body is deliberately never rendered.
  Those three are now literal `php artisan` lines.
- Two guards, because the existing test asserted on its own `Blade::render`
  output, which interpolates everywhere and so could never have caught the above:
  one rejects `{{` or `$assist` anywhere in a snippet body, and one rejects a
  byte-order mark, which Boost's frontmatter regex does not match and which would
  make it drop the skill silently.

## [0.2.0] - 2026-08-21

Adoption and agent support. The wire format is unchanged at `1.0`, and nothing in
the compiler or the HTTP surface changed, so upgrading from 0.1.0 is a
`composer update` and nothing else. 451 tests.

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

[Unreleased]: https://github.com/huy-tran/laravel-api-waypoint/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/huy-tran/laravel-api-waypoint/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/huy-tran/laravel-api-waypoint/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/huy-tran/laravel-api-waypoint/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/huy-tran/laravel-api-waypoint/releases/tag/v0.1.0
