# Spec 1: `hygo/laravel-api-waypoint` (Laravel package)

**Status:** ready to build
**Audience:** implementing engineer or coding agent
**Companion documents:** `api-waypoint-contract.md` (the wire format, normative), `spec-2-central-app-design.md` (the consumer)

---

## 1. Purpose

A dev-only Laravel package that introspects the host application and publishes a machine-readable description of every API endpoint: its input schema, its query-string contract, its auth requirements, and generation hints for producing realistic test payloads. A local companion app (the Central App) pulls this and builds editable, ready-to-send requests.

The package replaces the manual work of keeping Bruno and Postman collections in step with the code.

### In scope

- Compile a schema document from routes, Laravel Actions, Spatie Data classes, Spatie Query Builder configuration and Fractal transformers.
- Serve that document, plus three live-data helper endpoints, over dev-only HTTP routes.
- Expose the same compiler through Artisan for CI enforcement.

### Explicit non-goals for v1

- No UI of any kind.
- No MCP server. The compiler must be usable by one later, but do not build it.
- No OpenAPI export. Same reasoning: keep the compiler decoupled so it can be added.
- No `multipart/form-data` or file-upload payload generation. Detect and flag these endpoints in diagnostics, do not attempt to describe them.
- No inline `$request->validate()` static analysis. Endpoints that do not declare a Data class are reported, not inferred. (The resolver design must allow adding this later.)
- No response body schema derivation from Fractal transformers. Snapshots only.

---

## 2. Compatibility targets

| Dependency | Version |
|---|---|
| PHP | ^8.3 |
| Laravel | ^11.0 \|\| ^12.0 \|\| ^13.0 |
| `spatie/laravel-data` | ^4.0 |
| `lorisleiva/laravel-actions` | ^2.7 |
| `nwidart/laravel-modules` | ^11.0 \|\| ^12.0 (soft dependency, used only for module attribution) |
| `spatie/laravel-query-builder` | ^6.0 \|\| ^7.0 (soft) |
| `spatie/laravel-fractal` | ^6.0 (soft) |
| `laravel/sanctum` | ^4.0 (soft, required only for token minting) |

Soft dependencies must be detected at runtime with `class_exists()`. The package must install and function in an app that has none of them, degrading to "no module attribution / no query config / no transformer info".

---

## 3. Safety model

This is the first thing to build and the last thing to compromise on. The package exposes database reads, token minting and state seeding.

### 3.1 Route registration is conditional, not protected

Routes are registered **only** when all of the following hold. Failing any of them means the routes do not exist in the route table at all, so a probe gets Laravel's own 404 and cannot distinguish the package from an app that never installed it.

```php
class ApiWaypointServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerCompiler(); // always available, Artisan needs it in CI

        if (! $this->waypointShouldRegister()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/api-waypoint.php');
    }

    protected function waypointShouldRegister(): bool
    {
        return config('api-waypoint.enabled') === true
            && in_array(app()->environment(), config('api-waypoint.environments'), true)
            && filled(config('api-waypoint.secret'));
    }
}
```

- `api-waypoint.enabled` defaults to `false` in the published config. It must be turned on deliberately, per app.
- `api-waypoint.environments` defaults to `['local']`. `production` must be rejected even if someone adds it: hard-fail in the service provider with a clear exception rather than silently registering.
- `api-waypoint.secret` has no default. Empty means no routes.

### 3.2 Secret comparison

`VerifyWaypointSecret` middleware compares `X-Api-Waypoint-Secret` against config using `hash_equals()`. A mismatch aborts with **404**, not 403, and the response body is Laravel's standard `{"message": "Not Found."}`. Never leak that the route exists.

### 3.3 Reference lookups are whitelisted by the compiled schema

`GET /references/{table}/{column}` must not accept arbitrary tables. On boot of the request, compile (or read the cached) schema and build the set of `(table, column)` pairs that appear in any `x-laravel.exists` or `x-laravel.unique` declaration, plus any pair declared in `config('api-waypoint.references.extra')`. Anything outside that set is a 404.

Additional constraints:

- `where[...]` keys must exist as columns on the target table (verify with `Schema::hasColumn()`), and values are bound, never interpolated.
- `limit` is clamped to 50.
- `q` performs a `LIKE` against the configured label column only.
- Columns listed in `config('api-waypoint.references.redact')` (default: `password`, `remember_token`, `two_factor_secret`, `api_token`) are never returned in `context` and never usable as a label.

### 3.4 Token minting is whitelisted by role

`config('api-waypoint.tokens.roles')` maps a role name to an waypoint user resolver and an ability list. Only those role names are accepted. The resolver creates or finds a dedicated user whose email matches `config('api-waypoint.tokens.email_pattern')` (default `waypoint+{role}@{host}`), so waypoint users are identifiable and prunable, and a real customer account can never be impersonated by name collision. TTL is clamped to `config('api-waypoint.tokens.max_ttl_minutes')`, default 240.

### 3.5 Scenarios, not arbitrary factories

Per the agreed decision, `POST /scenarios` accepts a **name only**. Each app declares its scenarios in config:

```php
// config/api-waypoint.php
return [
    'scenarios' => [
        'paid_order' => Modules\Orders\Waypoint\Scenarios\PaidOrder::class,
        'suspended_customer' => Modules\Customers\Waypoint\Scenarios\SuspendedCustomer::class,
    ],
];
```

Each class implements `WaypointScenario` (see 6.4). Unknown name is a 422 listing the available names. There is no code path that accepts a class name, factory name or attribute array from the request body beyond the scenario's own declared, validated parameters.

### 3.6 Audit log

Every request to any waypoint route writes one line to a dedicated channel (`config('api-waypoint.log_channel')`, default `stack`): timestamp, route, actor (secret fingerprint, first 8 chars of a hash), and for scenarios and tokens the resolved name/role. This is cheap and makes "who seeded 400 orders" answerable.

### 3.7 Required tests

These are not optional. See section 10.

---

## 4. Package structure

```
src/
  ApiWaypointServiceProvider.php
  Contracts/
    ProvidesWaypointInput.php
    ProvidesWaypointQuery.php
    WaypointScenario.php
  Compiler/
    SchemaCompiler.php              # orchestrator, returns SchemaDocument
    RouteCollector.php
    ModuleResolver.php
    ActionResolver.php
    Input/
      InputResolver.php             # chain runner
      Resolvers/
        ContractInputResolver.php   # ProvidesWaypointInput
        HandleParameterResolver.php # type-hinted Data param
        NullInputResolver.php       # terminal, records diagnostic
    Data/
      DataSchemaCompiler.php        # Data class -> JSON Schema
      RuleMapper.php                # validation rule -> JSON Schema keywords
      EnumReader.php
      ComponentRegistry.php         # $ref registry + cycle guard
    Faker/
      FakerHintResolver.php
      StrategyVocabulary.php
    Query/
      QueryConfigExtractor.php
      QueryConfig.php               # value object, also used by host Actions
      RecordingQueryBuilderSpy.php  # optional resolver, see 5.6
    Response/
      ResponseDescriber.php
      TransformerReader.php
      SnapshotStore.php
    Support/
      CanonicalHasher.php
      SchemaDocument.php
  Http/
    Middleware/VerifyWaypointSecret.php
    Middleware/LogWaypointRequest.php
    Controllers/
      SchemaController.php
      ManifestController.php
      ReferenceController.php
      TokenController.php
      ScenarioController.php
  Console/
    SchemaCommand.php               # waypoint:schema
    CheckCommand.php                # waypoint:check
    SnapshotCommand.php             # waypoint:snapshot
  Recording/
    RecordsWaypointResponses.php    # opt-in middleware for snapshots
config/api-waypoint.php
routes/api-waypoint.php
workbench/                          # Testbench fixture app, see 10.2
tests/
```

---

## 5. The compilation pipeline

`SchemaCompiler::compile(): SchemaDocument` runs the stages below in order. Every stage is individually unit-testable and takes explicit inputs, no facades reached for mid-stage.

### 5.1 RouteCollector

Iterate `Route::getRoutes()`. Include a route when:

- its URI matches one of `config('api-waypoint.routes.include')` patterns (default `['api/*']`), and
- it does not match `config('api-waypoint.routes.exclude')` (default includes `api/_api-waypoint*`, Sanctum's CSRF route, Horizon, Telescope), and
- it has at least one of `config('api-waypoint.routes.required_middleware')` if that config is non-empty (default empty).

Emit one candidate per HTTP method, so a route registered for both PUT and PATCH yields two endpoints with distinct IDs.

**Endpoint ID:** prefer the route name with the app's API prefix stripped (`api.v1.orders.store` → `orders.store`). If the route is unnamed, derive `{module}.{method_lower}.{slugged_uri}` and add a diagnostic warning with code `unnamed_route`, because unnamed routes make IDs unstable across refactors and the Central App keys saved requests on them.

### 5.2 ModuleResolver

Given the action's FQCN, resolve the module by, in order:

1. `nwidart/laravel-modules`: match the class namespace against registered module namespaces via `Module::all()`.
2. The second segment of the namespace when the first is `Modules`.
3. `config('api-waypoint.default_module')`, default `"app"`.

Return `key` (snake_case) and `name` (as declared).

### 5.3 ActionResolver

Resolve `$route->getAction('uses')` to a class and method. Record:

- `class`
- `type`: `laravel-actions` when the class uses `Lorisleiva\Actions\Concerns\AsController`, else `controller`, else `closure` (closures are skipped with an `unsupported_action` diagnostic).
- `as_controller`: bool

### 5.4 InputResolver chain

Run resolvers in priority order, first non-null wins. The chain is registered in the service container so a future `InlineValidateResolver` can be appended without touching the compiler.

**Resolver 1, `ContractInputResolver`.** The Action implements:

```php
<?php

namespace Hygo\ApiWaypoint\Contracts;

interface ProvidesWaypointInput
{
    /**
     * FQCN of the Spatie Data class describing this endpoint's request body,
     * or null when the endpoint takes no body.
     */
    public static function waypointInput(): ?string;
}
```

This is the escape hatch for anything the reflection resolver cannot see. Highest priority so it always wins.

**Resolver 2, `HandleParameterResolver`.** Reflect the Action's `asController()` if present, else `handle()`. Take the first parameter whose type is a class extending `Spatie\LaravelData\Data` or implementing `Spatie\LaravelData\Contracts\BaseData`. Ignore parameters typed as models (route bindings), `Request`, or scalars.

**Resolver 3, `NullInputResolver`.** Always matches. Records `diagnostics.unmapped_routes[]` with a `reason` from this closed set:

| reason | meaning |
|---|---|
| `no_data_class` | Action has no Data parameter and no contract method |
| `multipart` | Route accepts file uploads (detected via a `UploadedFile`-typed Data property or a `file`/`image`/`mimes` rule) |
| `closure_action` | Route action is a closure |
| `unsupported_action` | Action class could not be reflected |

Endpoints with an unmapped input are still emitted in `endpoints[]` with `"input": null` so the Central App can list them as read-only, and are simultaneously listed in diagnostics. Do not silently drop them.

### 5.5 DataSchemaCompiler

The core of the package. Input: a Data class FQCN. Output: a JSON Schema object registered in `ComponentRegistry` under `{Module}.{ClassBasename}`, returning a `$ref`.

Procedure:

1. `app(DataConfig::class)->getDataClass($class)` for the property list. This is Spatie's own internal structure and gives types, nullability, `Optional`, `Lazy`, defaults, attributes and the input name mapper.
2. `$class::getValidationRules([])` for the rule strings. Some rules exist only here (added in a `rules()` method rather than as attributes), so both sources are needed. Merge, with attribute-derived facts taking precedence on conflict and a `rule_conflict` warning emitted.
3. For each property:
   - **Skip** properties marked `#[Computed]` or typed `Lazy`. These are output-only and must not appear in an input schema.
   - **JSON key** is the input-mapped name (`DataProperty::$inputMappedName`, falling back to the class-level mapper then `config('data.name_mapping_strategy')`). Record the PHP property name in `x-laravel.property`.
   - **Type** comes from the PHP type first, then narrowed by rules. Where a cast widens the accepted input (a `CarbonImmutable` property accepting an ISO string, an enum property accepting its backing value), the schema must describe **the accepted input**, not the PHP type. Maintain an explicit cast-to-input-type table for the common Spatie casts, and fall back to `string` with a `cast_input_assumed` warning for unknown casts.
   - **Nested Data class** → recurse, emit `$ref`.
   - **`DataCollection` / `array of Data`** → `type: array`, `items: $ref`, and record `x-laravel.data_collection_of`.
   - **Backed enum** → `enum` array of backing values, `x-laravel.enum_class`. Pure enums use case names.
   - **Optional** → omit from `required`, set `x-laravel.optional: true`.
   - **Nullable** → type becomes a two-member union with `"null"`.
4. Apply `RuleMapper` (5.7) to fold rule strings into JSON Schema keywords and `x-laravel`.
5. `ComponentRegistry` guards cycles: a Data class referencing itself (a tree structure) must emit a `$ref` to the already-registered component rather than recursing forever. Track an in-progress set.
6. Compute the component hash over the schema object with `x-laravel.hash` excluded.

### 5.6 QueryConfigExtractor

Spatie Query Builder's allowed lists are built inside a runtime method chain, so they cannot be read by reflection. Two resolvers, in order.

**Resolver 1, contract (required for full support).** The Action declares its query contract once and uses the same object when building the query, so there is a single source of truth:

```php
<?php

namespace Hygo\ApiWaypoint\Contracts;

use Hygo\ApiWaypoint\Compiler\Query\QueryConfig;

interface ProvidesWaypointQuery
{
    public static function waypointQuery(): QueryConfig;
}
```

`QueryConfig` is a fluent value object that can produce both the waypoint description and the actual `allowedFilters()` arguments:

```php
QueryConfig::make()
    ->exactFilter('status', values: OrderStatus::class, multiple: true)
    ->partialFilter('reference')
    ->partialFilter('customer.name', relation: 'customer')
    ->customFilter('placed_between', PlacedBetweenFilter::class, valueHint: 'date_range_csv')
    ->sorts(['placed_at' => 'desc', 'total_cents', 'reference'])
    ->includes(['customer', 'lines', 'lines.product', 'payments'])
    ->fields(['orders' => ['id', 'reference', 'status'], 'customers' => ['id', 'name']])
    ->pagination(perPage: 15, max: 100);
```

Provide a `HasWaypointQuery` trait giving the Action a `queryBuilder(Builder|string $subject)` helper that applies the config, so adopting the contract removes code rather than adding it. This is what makes the convention palatable.

**Resolver 2, recording spy (optional, off by default).** When `config('api-waypoint.query.probe')` is true and the Action exposes a `public function query(): QueryBuilder` method, call it with a `RecordingQueryBuilderSpy` that records `allowedFilters/Sorts/Includes/Fields` calls and never executes a database query. Emit `x-laravel.query_source: "probe"` and a `probed_query_config` warning so the Central App can show it as lower-confidence. Document loudly that this executes host code and is unsuitable for Actions with side effects in `query()`.

Endpoints with neither resolver get `"query": null` and a `no_query_config` warning if the route is a GET returning a collection.

### 5.7 RuleMapper

Normative mapping from Laravel rules to JSON Schema. Unlisted rules are recorded in `x-laravel.rules` verbatim and, when they are custom `Rule` objects or closures, add an `opaque_rule` warning.

| Rule | Effect |
|---|---|
| `required` | add to parent `required[]` |
| `sometimes`, Spatie `Optional` | omit from `required[]`, `x-laravel.optional: true` |
| `present` | add to `required[]`, `x-laravel.present_only: true` |
| `nullable` | type union with `"null"` |
| `string` | `type: string` |
| `integer`, `int` | `type: integer` |
| `numeric` | `type: number` |
| `decimal:p,s` | `type: number`, `multipleOf: 10^-s` |
| `boolean` | `type: boolean` |
| `array` | `type: array` |
| `min:n` | `minLength` / `minimum` / `minItems` by resolved type |
| `max:n` | `maxLength` / `maximum` / `maxItems` by resolved type |
| `between:a,b` | both of the above |
| `size:n` | exact bound pair for the resolved type |
| `gt:n`, `gte:n`, `lt:n`, `lte:n` (literal) | `exclusiveMinimum` / `minimum` / `exclusiveMaximum` / `maximum` |
| `gt:field`, `lte:field` etc. (field reference) | `x-laravel.conditional_rules[]`, and if the referenced name is not a sibling property, `x-faker.strategy: "unresolvable"` |
| `digits:n` | `type: string` (or integer), `pattern: ^[0-9]{n}$` |
| `digits_between:a,b` | `pattern: ^[0-9]{a,b}$` |
| `in:a,b,c` | `enum: [a,b,c]` |
| `Rule::enum(X::class)` | `enum` from cases, `x-laravel.enum_class` |
| `email` | `format: "email"` |
| `url`, `active_url` | `format: "uri"` |
| `uuid` | `format: "uuid"` |
| `ulid` | `pattern: ^[0-9A-HJKMNP-TV-Z]{26}$` |
| `ip`, `ipv4`, `ipv6` | `format: "ipv4"` / `"ipv6"` / `"ip"` |
| `json` | `type: string`, `x-laravel.json: true` |
| `timezone` | `type: string`, `x-faker.strategy: "timezone"` |
| `date` | `format: "date-time"` |
| `date_format:F` | `x-laravel.date_format`, `x-faker.format` set to the same |
| `after:X`, `before:X`, `after_or_equal`, `before_or_equal` | `x-laravel.date_bounds`, `x-faker.range` |
| `regex:/…/` | `pattern` **only when safely convertible** to ECMA regex: reject if it uses PCRE-only constructs (lookbehind with quantifiers, `\A`, `\z`, possessive quantifiers, inline flags other than `i`). When rejected, keep the raw rule in `x-laravel.rules` and set `x-faker.strategy: "unresolvable"` with reason `pcre_only_pattern`. |
| `not_regex` | `x-laravel` only, never `pattern` |
| `starts_with:a,b` | `pattern` anchored alternation |
| `ends_with:a,b` | `pattern` anchored alternation |
| `alpha`, `alpha_num`, `alpha_dash` | corresponding `pattern` |
| `exists:table,column` | `x-laravel.exists`, `x-faker.strategy: "reference"` |
| `unique:table,column` | `x-laravel.unique`, `x-faker.unique: true` |
| `confirmed` | emit a sibling property `{key}_confirmation` with the same schema and `x-faker.strategy: "mirror"`, `x-faker.mirrors: "{key}"` |
| `same:field`, `different:field` | `x-laravel.conditional_rules[]`, `mirror` / `distinct_from` strategy |
| `required_if`, `required_unless`, `required_with`, `required_with_all`, `required_without`, `prohibited_if`, `prohibited_unless`, `missing_if` | `x-laravel.conditional_rules[]` with rule, field, values; `x-faker.required_when` / `omit_when` |
| `distinct` | `uniqueItems: true` on the parent array |
| `accepted` | `enum: [true, "yes", "on", 1]` |
| `file`, `image`, `mimes`, `mimetypes` | do **not** map. Mark the endpoint `multipart` and add it to `unmapped_routes`. |
| custom `Rule` object, closure, `Rule::when()` | `x-laravel.rules` verbatim, `opaque_rule` warning |

Nested array rules (`lines.*.product_id`) are folded into the `items` schema of the parent array. When the parent is a `DataCollection`, the nested rules must merge with the item Data class's own schema rather than duplicating it.

### 5.8 FakerHintResolver

Produce `x-faker` for every property. Precedence, highest first:

1. `config('api-waypoint.faker.overrides')` keyed by `"Module.DataClass.property"` or `"*.property"` for a global rule (`'*.email' => ['strategy' => 'internet.email']`).
2. A `#[WaypointFaker(strategy: 'person.lastName')]` attribute on the property.
3. `x-laravel.exists` present → `strategy: "reference"` with the table, column and any constraint declared in the attribute.
4. `enum` present → `strategy: "enum"`.
5. `pattern` present and safely convertible → `strategy: "pattern"` with a symbol mask (`#` digit, `?` letter) derived from the regex when it is a simple anchored literal-plus-quantifier form, else `strategy: "unresolvable"`.
6. `format` present → the matching strategy from the vocabulary (`email`, `uri`, `uuid`, `date-time` → `date`).
7. Property name heuristics against `config('api-waypoint.faker.name_hints')`, a shipped default map (`email`, `first_name`, `last_name`, `phone`, `mobile`, `abn`, `postcode`, `suburb`, `state`, `country`, `company`, `street`, `url`, `slug`, `title`, `description`, `notes`, `price_cents`, `amount_cents`, `quantity`, `latitude`, `longitude`). Australian defaults where relevant (`state` → AU states, `postcode` → 4 digits).
8. Resolved JSON type → `int`, `float`, `boolean`, `sentence`, `key_value_map`, `collection`.
9. Nothing matched → `strategy: "unresolvable"` with a `reason`.

Also set, where applicable:

- `include_probability` on every optional or nullable property. Default `config('api-waypoint.faker.default_include_probability')`, 0.5, overridable per property by attribute.
- `count: {min, max}` on arrays, derived from `minItems`/`maxItems` clamped to a sane ceiling (default max 3) so a `maxItems: 50` array does not generate 50 items by default.
- `unique: true` where a `unique:` rule exists.

`StrategyVocabulary` holds the closed list and a `version` constant. The compiler must fail its own test suite if a resolver emits a strategy not in the list.

### 5.9 ResponseDescriber

- `success_status`: from an `#[WaypointResponse(status: 201)]` attribute if present, else inferred from HTTP method and route name (`store` → 201, `destroy` → 204, else 200).
- `transformer`: from the attribute, or from a `ProvidesWaypointResponse` contract. Do not attempt to guess.
- `available_includes` / `default_includes`: reflect the transformer's protected `$availableIncludes` / `$defaultIncludes`.
- `shape`: `"opaque"` for Fractal, `"collection"` when the route name ends `.index` or the transformer is used with a collection helper, `"data_object"` when the endpoint returns a Data class (reflect the return type, and if it is a Data class, compile it into `components.data_objects` and reference it, since Data output is introspectable).
- `snapshot`: read from `SnapshotStore` if one exists for this endpoint.

### 5.10 SnapshotStore and response recording

`RecordsWaypointResponses` middleware, opt-in via `config('api-waypoint.snapshots.enabled')`. On a successful response to a collected route, if no snapshot exists or the stored one is older than `snapshots.ttl_days` (default 30), write a sanitised copy to `storage/app/api-waypoint/snapshots/{endpoint_id}.json`.

Sanitisation is a deny-list of key names (`config('api-waypoint.snapshots.redact')`, defaults covering `password`, `token`, `secret`, `authorization`, `card`, `iban`, `tfn`, `abn`) applied recursively, replacing values with `"[redacted]"`. Truncate arrays to the first 3 elements and strings to 500 chars so snapshots stay small.

`waypoint:snapshot --prune` clears them.

### 5.11 CanonicalHasher

`sha256` over a canonical JSON encoding: recursively sort object keys, no whitespace, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Output `"sha256:"` plus the first 12 hex characters.

Hash inputs, specified exactly so hashes are stable and meaningful:

- **Endpoint hash:** method, uri, module, auth block, resolved input `$ref` target's hash, query block, `success_status`. **Excludes** the snapshot and any `generated_at`.
- **Data object hash:** the component schema with `x-laravel.hash` removed. Excludes descriptions? No: include them, a changed description is a meaningful change to a payload editor.
- **Document hash:** the whole document with `generated_at` and all snapshots removed.

The exclusions matter: without them, every recompile produces a new hash and the Central App reports spurious drift on every sync.

---

## 6. HTTP endpoints

Routes live in `routes/api-waypoint.php`, prefixed by `config('api-waypoint.prefix')` (default `_api-waypoint`), with `VerifyWaypointSecret` and `LogWaypointRequest` applied to all. The wire format for every response below is normative in `api-waypoint-contract.md`; this section covers behaviour only.

### 6.1 `GET /` (schema)

Returns the full document. Sets `ETag` to the document hash and honours `If-None-Match` with a 304. `Cache-Control: no-store`.

Accepts `?format=1.0`. If the requested format is not supported, return 409 per the contract. Compilation runs on every request in `local` (correctness over speed; a route walk plus reflection on a few hundred endpoints should land under 500ms, and if it does not, that is a bug worth fixing rather than caching around). In `staging`, cache the compiled document in the configured cache store keyed on the document hash inputs, with `waypoint:schema --clear` to bust it.

### 6.2 `GET /manifest`

Hashes only, per the contract. Must be compiled from the same `SchemaDocument`, never from a separate cheaper path, or the two can disagree.

### 6.3 `GET /references/{table}/{column}`

Query parameters: `limit` (1..50, default 20), `label` (column name, validated against the table and the redact list), `q` (LIKE fragment against the label column), `where[col]=value` (repeatable). Behaviour per 3.3. Returns the `hint.scenario` block when the result is empty and a scenario is declared for that table in `config('api-waypoint.references.scenario_hints')`.

### 6.4 `POST /scenarios`

```php
<?php

namespace Hygo\ApiWaypoint\Contracts;

interface WaypointScenario
{
    /** Human-readable description shown in the Central App. */
    public static function description(): string;

    /** Parameter schema, same JSON-Schema-ish shape as an endpoint input. Return [] for none. */
    public static function parameters(): array;

    /**
     * Create the state. Return an array of records shaped per the contract's
     * scenario response. Implementations must be idempotent-safe to call repeatedly.
     */
    public function run(array $parameters): array;
}
```

Request body: `{"scenario": "paid_order", "parameters": {...}}`. Parameters are validated against the scenario's own declared schema before `run()` is called. Wrap the whole run in a transaction and record created model keys against a generated `cleanup_token` in a package table (`api_waypoint_scenario_runs`), so `DELETE /scenarios/{cleanup_token}` can delete them in reverse order.

`GET /scenarios` lists available scenarios with descriptions and parameter schemas, so the Central App can render them without hardcoding.

### 6.5 `POST /tokens`

Behaviour per 3.4. Response per the contract. Revoke previously issued waypoint tokens for the same role before minting a new one, so the tokens table does not grow without bound.

---

## 7. Configuration

Publish `config/api-waypoint.php`. Every key documented inline. Required shape:

```php
return [
    'enabled' => env('API_WAYPOINT_ENABLED', false),
    'environments' => ['local'],
    'secret' => env('API_WAYPOINT_SECRET'),
    'prefix' => '_api-waypoint',
    'log_channel' => env('API_WAYPOINT_LOG_CHANNEL', 'stack'),
    'default_module' => 'app',

    'routes' => [
        'include' => ['api/*'],
        'exclude' => ['api/_api-waypoint*', 'sanctum/*', 'horizon/*', 'telescope/*'],
        'required_middleware' => [],
    ],

    'query' => [
        'probe' => false,
    ],

    'faker' => [
        'default_include_probability' => 0.5,
        'array_count_ceiling' => 3,
        'overrides' => [
            // '*.email' => ['strategy' => 'internet.email'],
        ],
        'name_hints' => [/* shipped defaults, see 5.8 */],
    ],

    'references' => [
        'extra' => [],
        'redact' => ['password', 'remember_token', 'two_factor_secret', 'api_token'],
        'scenario_hints' => [
            // 'orders' => 'paid_order',
        ],
    ],

    'tokens' => [
        'enabled' => true,
        'max_ttl_minutes' => 240,
        'email_pattern' => 'waypoint+{role}@{host}',
        'roles' => [
            // 'admin' => ['abilities' => ['*'], 'resolver' => AdminWaypointUser::class],
        ],
    ],

    'scenarios' => [],

    'snapshots' => [
        'enabled' => false,
        'ttl_days' => 30,
        'redact' => ['password', 'token', 'secret', 'authorization', 'card', 'iban', 'tfn', 'abn'],
    ],
];
```

---

## 8. Artisan commands

| Command | Behaviour |
|---|---|
| `waypoint:schema` | Compile and write the document. `--output=path` (default stdout), `--pretty`, `--clear` to bust the staging cache. Exit 0 on success. |
| `waypoint:check` | Compile and report. `--fail-on-unmapped` exits 1 when `diagnostics.unmapped_routes` is non-empty. `--fail-on-warning` exits 1 on any warning. `--baseline=path` compares against a committed document and exits 1 when any endpoint or component hash changed, printing a readable diff. |
| `waypoint:snapshot` | `--prune` deletes stored snapshots. `--list` shows what exists and its age. |

`waypoint:check` must work with the package "disabled" (config `enabled => false`), because CI should not need the HTTP surface switched on. Gate only route registration on `enabled`, never the compiler.

Intended CI usage:

```yaml
- run: php artisan waypoint:check --fail-on-unmapped
- run: php artisan waypoint:check --baseline=storage/api-waypoint-baseline.json
```

The second is optional per project and is the "collection cannot go stale" enforcement: a PR that changes an endpoint must regenerate the committed baseline.

---

## 9. Performance targets

On an app with 400 routes and 200 Data classes:

- Cold compile under 1500ms.
- `GET /manifest` under 300ms when the document is cached, under the cold compile time otherwise.
- Memory under 128MB.

Reflection results (per class, not per route) must be memoised within a single compile. If cold compile exceeds the target, the likely cause is recompiling shared Data classes; check `ComponentRegistry` is hit before compilation, not after.

---

## 10. Test plan

### 10.1 Unit tests, per component

- `RuleMapper`: one test per row of the 5.7 table, plus the PCRE rejection cases, plus rule-order independence (`['nullable','string','max:50']` and `['max:50','string','nullable']` produce identical schemas).
- `DataSchemaCompiler`: nested Data, `DataCollection`, backed enum, pure enum, `Optional`, nullable, `Lazy` excluded, `#[Computed]` excluded, input name mapping (camel to snake), self-referencing Data class (cycle guard), cast-widened input types.
- `FakerHintResolver`: precedence order, one test per level, plus `unresolvable` for a field-referencing `lte`.
- `CanonicalHasher`: key order independence, and the critical one, **hash stability across two compiles of an unchanged app** including when snapshots exist.
- `QueryConfigExtractor`: contract path, probe path, absent path.
- `ModuleResolver`: nwidart path, `Modules\X` namespace path, fallback.

### 10.2 Integration, against a Testbench workbench app

Build `workbench/` as a miniature version of a real host app: two modules, six endpoints covering create with nested collection, index with full query config, show with route binding, a refund-style endpoint with an unresolvable field, a multipart upload endpoint (must land in diagnostics), and one deliberately non-conforming Action with inline `validate()` (must land in `unmapped_routes`).

Assert the compiled document against a committed golden file. This is the highest-value test in the suite: it catches every regression in one assertion and doubles as the fixture the Central App is built against (see spec 2, section 3).

Provide `php artisan waypoint:schema --output=tests/fixtures/golden.json` regeneration and require a reviewer to eyeball the diff.

### 10.3 Security tests, non-negotiable

- Routes are absent from the route table when `enabled => false`.
- Routes are absent when the environment is not in the allow list.
- Service provider throws when `production` appears in `environments`.
- Every route returns 404, not 403, with a missing secret, a wrong secret, and a secret differing only in length.
- `/references/{table}/{column}` returns 404 for a table not present in the compiled schema, even when the table exists in the database.
- `/references` rejects a `where[]` key that is not a column, and never interpolates values (assert with a `'; DROP TABLE` style value that the query is bound).
- Redacted columns never appear in a references response, as a value or a label.
- `/tokens` rejects a role not in config, and the minted user's email matches the waypoint pattern.
- `/scenarios` rejects an unknown name, and rejects a body attempting to pass a class name or factory.
- Snapshot sanitisation removes every configured key at every nesting depth.

### 10.4 Contract conformance

A test that validates the compiled document against a JSON Schema of the contract itself (write that meta-schema as part of this work, `resources/schema/api-waypoint-1.0.json`). Both the package and the Central App then have something objective to test against, which is what keeps two independently built codebases honest.

---

## 11. Acceptance criteria

The package is done when all of the following are true.

1. `composer require --dev hygo/laravel-api-waypoint` into a fresh Laravel 12 app with the stack listed in section 2, publish config, set two env vars, and `GET /_api-waypoint` returns a document that validates against `api-waypoint-1.0.json`.
2. Pointed at a real, production-sized API application, `waypoint:check` reports zero `opaque_rule` warnings for endpoints that use only the rules in the 5.7 table, and every unmapped route has a correct, actionable `reason`.
3. Two consecutive compiles of an unchanged codebase produce identical document, endpoint and component hashes.
4. Changing one property on one Data class changes that component's hash and the hash of exactly the endpoints that reference it, and nothing else.
5. Every security test in 10.3 passes.
6. The golden-file integration test passes and its fixture is committed for the Central App to build against.
7. `waypoint:check --fail-on-unmapped` runs green in CI on at least one real app.
8. `README` covers: install, the two required env vars, adopting `ProvidesWaypointQuery` (with a before/after showing the code it replaces), the four unmapped reasons and how to fix each, and a prominent security section stating the package must never be enabled in production.

---

## 12. Build order

1. Skeleton, config, service provider with the conditional registration and its security tests. Nothing else until 10.3's registration tests pass.
2. `RouteCollector`, `ModuleResolver`, `ActionResolver`, and the workbench app with two endpoints. Output a document containing endpoints with `"input": null`.
3. `RuleMapper` and `DataSchemaCompiler` with `ComponentRegistry`. This is the bulk of the work.
4. `FakerHintResolver` and `StrategyVocabulary`.
5. `CanonicalHasher`, the manifest endpoint, hash stability tests.
6. `QueryConfigExtractor` with the contract resolver and the `HasWaypointQuery` trait.
7. `ReferenceController`, with its whitelist derived from step 3's output.
8. `TokenController`, `ScenarioController`.
9. `ResponseDescriber`, `SnapshotStore`, the recording middleware.
10. Artisan commands, the meta-schema, the golden fixture, README.

Steps 1 to 5 constitute a useful package on their own: the Central App can be built against that output while 6 to 10 land.
