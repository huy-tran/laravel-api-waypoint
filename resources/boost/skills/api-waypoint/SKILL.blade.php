---
name: api-waypoint
description: "Use this skill for any work involving the API Waypoint schema in a Laravel application: setting the package up (publishing config, generating the secret, setting routes.include to the right prefix), running waypoint:install / waypoint:check / waypoint:schema / waypoint:snapshot, resolving endpoints reported as unmapped (no_data_class, multipart, closure_action, unsupported_action), declaring a Spatie Query Builder contract via ProvidesWaypointQuery and QueryConfig, adding WaypointEndpoint / WaypointResponse / WaypointPrecondition / WaypointFaker attributes, implementing ProvidesWaypointInput, recording response snapshots, or debugging a 404 from the api-waypoint HTTP surface. Also trigger when an endpoint's request body, query parameters, auth requirements or generated test payload need to be described, corrected, or explained to a client application."
license: MIT
metadata:
  author: hygo
---

# API Waypoint

A development-only package that compiles a machine-readable description of every API endpoint in this application: input schema (JSON Schema draft 2020-12), query-string contract, auth requirements, and hints for generating realistic payloads. A local companion app (the Central App) pulls that document and builds ready-to-send requests, so hand-maintained Bruno and Postman collections stop going stale.

**This must never be enabled in production.** It exposes database reads, Sanctum token minting and state seeding behind a single shared secret.

## Commands

| Command | Use |
|---|---|
| `{{ $assist->artisanCommand('waypoint:install') }}` | Publish config, detect the route prefix, write the local env keys |
| `{{ $assist->artisanCommand('waypoint:check') }}` | Report gaps, warnings and drift. `--fail-on-unmapped`, `--fail-on-warning`, `--baseline=path` |
| `{{ $assist->artisanCommand('waypoint:schema') }}` | Write the document. `--output=path`, `--pretty`, `--clear` |
| `{{ $assist->artisanCommand('waypoint:snapshot') }}` | `--list` stored response snapshots, `--prune` to delete them |

The compiler is never gated on `enabled`: only route registration is. `waypoint:check` therefore works in CI, and in any checkout, with the HTTP surface switched off. Never enable the surface to run a check.

## MCP tools

When Laravel Boost is installed, three read-only tools expose the same compiled document. Prefer them over reading the JSON: they return only what was asked for, and they recompile on every call, so they never answer from before your last edit. Like the commands, they need neither the HTTP surface nor the shared secret.

| Tool | Returns |
|---|---|
| `waypoint-check` | Unmapped routes with a remedy each, warnings grouped by code, the document hash. Optional `reason` filter and `warnings: false`. |
| `waypoint-endpoints` | One line per endpoint: id, method, URI, module, auth, input component, whether it has a query contract. Filter by `module`, `search`, or `unmapped_only`. |
| `waypoint-endpoint` | One endpoint in full, with every referenced Data class resolved transitively, plus its error responses and any warnings about it. Takes `id`. |

The normal loop: `waypoint-endpoints` to find the endpoint, `waypoint-endpoint` to read its contract, `waypoint-check` after changing anything.

## Setup

Run `{{ $assist->artisanCommand('waypoint:install') }}`. It publishes the config, detects which URI prefix this application registers its API under, writes `routes.include` to match, and adds two keys to `.env`. It refuses to run in production, never overwrites an existing secret, and leaves a customised `routes.include` alone rather than clobbering it.

The equivalent by hand:

@boostsnippet("Manual setup", "shell")
composer require --dev hygo/laravel-api-waypoint
php artisan vendor:publish --tag=api-waypoint-config
php artisan vendor:publish --tag=api-waypoint-migrations   # only if you use scenarios
@endboostsnippet

@boostsnippet("Local .env only", "dotenv")
API_WAYPOINT_ENABLED=true
API_WAYPOINT_SECRET=   # php -r "echo bin2hex(random_bytes(32));"
@endboostsnippet

### routes.include is the setup step that actually goes wrong

The shipped default is `['api/*']`. That is correct for an application routing through `routes/api.php`, and matches **nothing** in one that registers `v1/orders` from a per-module or per-file route registrar. The symptom is a document with zero endpoints, which reads as "the package is broken" rather than as one wrong config line. Set `routes.include` to the prefix the route table actually shows:

@boostsnippet("config/api-waypoint.php", "php")
'routes' => [
    'include' => ['v1/*'],          // whatever this app really serves
    'exclude' => ['_api-waypoint*', 'sanctum/*', 'horizon/*'],
    'required_middleware' => [],     // when non-empty, a route must carry one of these
],
@endboostsnippet

## The HTTP surface

Registration is conditional, not protected. Routes exist only when **all three** hold: `api-waypoint.enabled === true`, the current environment is listed in `api-waypoint.environments`, and `api-waypoint.secret` is non-empty. Fail any one and the routes are absent from the route table entirely.

| Route | Purpose |
|---|---|
| `GET {prefix}` | the whole document |
| `GET {prefix}/manifest` | hashes only, for cheap refresh checks |
| `GET {prefix}/references/{table}/{column}` | live values for `exists:` fields |
| `GET {prefix}/scenarios`, `POST {prefix}/scenarios`, `DELETE {prefix}/scenarios/{token}` | list, run and undo a scenario |
| `POST {prefix}/tokens` | mint a short-lived role token |

`{prefix}` is `api-waypoint.prefix`, default `_api-waypoint`. Every request carries the shared secret as the `X-Api-Waypoint-Secret` header.

**The document is served at the prefix root**, `{prefix}` with nothing after it. Its route *name* is `api-waypoint.schema`, which is not a path segment: `{prefix}/schema` is a 404.

### A 404 is ambiguous by design

A secret mismatch returns 404, never 403, with Laravel's own `{"message": "Not Found."}`, so a probe cannot discover that anything is there. The cost is that an unregistered surface and a wrong secret look identical. Do not guess between them: ask the route table, which involves neither the secret nor HTTP.

@boostsnippet("Telling the two apart", "shell")
php artisan route:list --path=api-waypoint
# 7 routes listed  -> registered; a 404 means the secret did not match
# no routes listed -> one of the three registration conditions is unmet
@endboostsnippet

When checking over HTTP, keep a known-good control URL in the comparison so "app unreachable" is distinguishable from "guard said no".

## Making an endpoint describable

An endpoint the compiler cannot fully describe is **still emitted** in `endpoints[]` with `"input": null`, and **simultaneously** listed in `diagnostics.unmapped_routes`. Nothing is silently dropped. Every entry carries exactly one reason:

| Reason | Meaning | Fix |
|---|---|---|
| `no_data_class` | The action takes no Spatie Data parameter and does not implement `ProvidesWaypointInput`. Usually an inline `$request->validate()`, which is not introspected. | Type-hint a Data class on `handle()` or `asController()`, or implement `ProvidesWaypointInput`. |
| `multipart` | The endpoint accepts file uploads, detected from an `UploadedFile`-typed property or a `file` / `image` / `mimes` rule. Out of scope. | Split the upload into its own endpoint, or exclude the route. The endpoint still appears, without a body schema. |
| `closure_action` | The route action is a closure, so there is nothing to reflect. | Move the closure into an Action or controller class. |
| `unsupported_action` | The class exists but could not be reflected, or a declared Data class could not be compiled. | Check it is autoloadable and not abstract. If a Data class failed, look for an `uncompilable_data_class` warning naming why. |

A GET or DELETE with no Data class is **not** reported: there is no body to describe, so it is not a gap.

Prefer fixing the action over excluding the route. Excluding hides the endpoint from the Central App; fixing it is usually one type-hint.

@boostsnippet("Declaring the body when reflection cannot see it", "php")
class ImportOrders implements ProvidesWaypointInput
{
    public static function waypointInput(): ?string
    {
        return ImportOrdersData::class;   // or null: "this endpoint takes no body"
    }
}
@endboostsnippet

Returning `null` is a positive statement, not a gap, and is not reported.

## Query contracts

Spatie Query Builder assembles its allowed lists inside a runtime method chain, so nothing can read them by reflection. Declare them once as a `QueryConfig` and build the query from the same object; the description then cannot drift from what the endpoint enforces. This is fewer lines than the chain it replaces, and it enforces the `per_page` ceiling rather than repeating it.

@boostsnippet("Declare once, use twice", "php")
class ListOrders implements ProvidesWaypointQuery
{
    use AsAction;
    use HasWaypointQuery;

    public static function waypointQuery(): QueryConfig
    {
        return QueryConfig::make()
            ->exactFilter('status', values: OrderStatus::class, multiple: true)
            ->partialFilter('reference')
            ->partialFilter('customer.name', relation: 'customer')
            ->customFilter('placed_between', PlacedBetweenFilter::class, valueHint: 'date_range_csv')
            ->sorts(['placed_at' => 'desc', 'total_cents', 'reference'])
            ->includes(['customer', 'lines', 'lines.product'])
            ->countInclude('payments')
            ->fields(['orders' => ['id', 'reference', 'status']])
            ->pagination(perPage: 15, max: 100);
    }

    public function asController()
    {
        return $this->waypointPaginate($this->queryBuilder(Order::class));
    }
}
@endboostsnippet

Passing an enum class as `values:` puts its cases into the filter's `allowed_values` automatically. Other builders: `beginsWithFilter`, `endsWithFilter`, `scopeFilter`, `trashedFilter`, `sort`, `include`, `cursorPagination`.

A collection endpoint with no declared contract raises a `no_query_config` warning. That is the compiler saying the Central App cannot offer filters, sorts or includes for it, not that anything is broken.

Do not switch on `query.probe` to avoid declaring a contract: it calls `query()` methods with a recording spy, which **executes host code**, and every result is flagged with a `probed_query_config` warning.

## Attributes

For facts the compiler cannot guess at:

@boostsnippet("Endpoint-level declarations", "php")
#[WaypointEndpoint(summary: 'Refund a paid order', roles: ['admin'])]
#[WaypointResponse(status: 202, transformer: RefundTransformer::class, errors: [409])]
#[WaypointPrecondition('Order must be in the paid state', scenario: 'paid_order')]
class RefundOrder { /* ... */ }
@endboostsnippet

`WaypointEndpoint` also takes `module`, `deprecated` and `abilities`. `WaypointResponse` also takes `serializer` and `shape`.

@boostsnippet("Per-property generation overrides", "php")
#[WaypointFaker(strategy: 'pattern', pattern: 'ORD-######', includeProbability: 0.6)]
public ?string $reference = null;
@endboostsnippet

`WaypointFaker` also takes `format`, `values`, `unique`, `min`, `max`, `length`, `constraint` and `reason`. Strategies are abstract: the package never names a generator library method, so the Central App maps a strategy to whatever it generates with.

## Response snapshots

The compiler will not derive a response schema from a Fractal transformer, because `transform()` is arbitrary PHP and a guessed shape is worse than an honest `"shape": "opaque"`. Record a real one instead: append `RecordsWaypointResponses` to the api middleware in `bootstrap/app.php` (local only) and set `API_WAYPOINT_SNAPSHOTS=true`.

Snapshots are sanitised with a recursive deny-list, truncated to 3 array elements and 500 characters, and never affect a hash, so recording one does not make the Central App report drift.

## Safety rules

- Never set `API_WAYPOINT_ENABLED=true` anywhere but local, and never commit a real secret.
- Never add `production` to `api-waypoint.environments`. The service provider throws at boot if it is listed, deliberately.
- Never widen `references.redact` away from the credential columns, and do not add a table to `references.extra` to reach data the schema does not already reference.
- Token minting only accepts role names declared in `tokens.roles`, and re-checks the resolved user's email against `tokens.email_pattern`. Do not write a resolver that returns a real customer account.
- `POST /scenarios` accepts a declared scenario *name*, never a class, factory or attribute array. Keep it that way.
