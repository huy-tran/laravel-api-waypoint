# Changelog

All notable changes to `hygo/laravel-api-waypoint` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The **wire format** is versioned separately from the package, as
`schema_format_version` in the document. A breaking change to the wire format is a
new format version, not merely a new package major.

## [Unreleased]

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

[Unreleased]: https://github.com/hygo/laravel-api-waypoint/commits/main
