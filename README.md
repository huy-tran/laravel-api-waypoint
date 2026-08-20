# hygo/laravel-api-waypoint

A dev-only Laravel package that introspects your application and publishes a machine-readable description of every API endpoint: its input schema, its query-string contract, its auth requirements, and hints for generating realistic test payloads.

A local companion app (the Central App) pulls that document and builds editable, ready-to-send requests. The point is to stop hand-maintaining Bruno and Postman collections that go stale the moment someone adds a field.

> ## Security, read this first
>
> **This package must never be enabled in production.**
>
> It exposes database reads, Sanctum token minting and state seeding behind a single shared secret. That is an acceptable trade for a local development tool and completely unacceptable anywhere near customer data.
>
> Three independent conditions must all hold before a single route is registered, and the service provider throws at boot if `production` appears in the permitted environment list at all. See [Safety model](#safety-model).

---

## Requirements

| Dependency | Version | |
|---|---|---|
| PHP | ^8.3 | |
| Laravel | ^12.0 \|\| ^13.0 | |
| `spatie/laravel-data` | ^4.0 | required |
| `lorisleiva/laravel-actions` | ^2.7 | optional |
| `nwidart/laravel-modules` | ^11.0 \|\| ^12.0 | optional, module attribution |
| `spatie/laravel-query-builder` | ^6.0 \|\| ^7.0 | optional, query contracts |
| `spatie/laravel-fractal` | ^6.0 | optional, transformer includes |
| `laravel/sanctum` | ^4.0 | optional, token minting |

Every optional dependency is detected at runtime. The package installs and works without any of them; it just degrades to "no module attribution / no query config / no transformer info".

Laravel 11 is not supported. Its security-fix window closed in March 2026, every 11.x release now carries an unpatched advisory, and Composer refuses to install advisory-affected packages by default. Supporting a version that cannot be installed without switching that protection off would be a claim rather than a fact.

---

## Install

```bash
composer require --dev hygo/laravel-api-waypoint
php artisan vendor:publish --tag=api-waypoint-config
php artisan vendor:publish --tag=api-waypoint-migrations   # only if you use scenarios
```

Then set **two** environment variables, in your local `.env` only:

```dotenv
API_WAYPOINT_ENABLED=true
API_WAYPOINT_SECRET=  # php -r "echo bin2hex(random_bytes(32));"
```

Check it:

```bash
curl -H "X-Api-Waypoint-Secret: $API_WAYPOINT_SECRET" http://your-app.test/v1/api-waypoint
```

You should get a document whose `schema_format_version` is `1.0`. If you get a 404, one of the three registration conditions is not met: see below.

---

## Safety model

### Registration is conditional, not protected

Routes are registered **only** when all three hold:

| Condition | Default |
|---|---|
| `api-waypoint.enabled === true` | `false` |
| the current environment is in `api-waypoint.environments` | `['local']` |
| `api-waypoint.secret` is non-empty | no default |

Fail any one and the routes are absent from the route table entirely, so a probe gets Laravel's own 404. There is no "registered but forbidden" state to discover.

`production` in `api-waypoint.environments` is a **hard boot failure**, not a silent decline. A silent decline hides the mistake until somebody "fixes" it by making the config worse.

### The secret

Sent as `X-Api-Waypoint-Secret` and compared with `hash_equals()` over hashes of both sides, so the comparison is constant-time for any presented length. A mismatch is a **404**, never a 403, with Laravel's standard `{"message": "Not Found."}`.

### Reference lookups are whitelisted by the compiled schema

`GET /references/{table}/{column}` reads a `(table, column)` pair only if it appears in an `exists:` or `unique:` rule somewhere in the compiled document, in a route-model binding, or in `references.extra`. A table that plainly exists in your database but is named nowhere in the schema is a 404.

On top of that: `where[]` keys are checked with `Schema::hasColumn()`, values are always bound, `limit` is clamped to 50, and columns in `references.redact` can be neither read, labelled by, nor filtered on.

### Token minting is whitelisted by role

Only role names in `tokens.roles` are accepted. Each role's resolver is handed a waypoint email derived from `tokens.email_pattern` (default `waypoint+{role}@{host}`), and the controller **re-checks** the returned user's email against it. A resolver that goes looking for a real customer account cannot get a token issued for it.

### Scenarios accept a name, not code

`POST /scenarios` takes a name from `api-waypoint.scenarios` and that scenario's own declared, validated parameters. There is deliberately no code path that accepts a class name, factory name or attribute array.

### Audit log

Every waypoint request writes one line to `api-waypoint.log_channel`: route, method, status, an 8-character fingerprint of the presented secret, and the resolved scenario or role where relevant. It makes "who seeded 400 orders" answerable.

---

## What it produces

```
GET    /v1/api-waypoint                          the whole document
GET    /v1/api-waypoint/manifest                 hashes only, for cheap refresh checks
GET    /v1/api-waypoint/references/{table}/{col} live values for exists: fields
GET    /v1/api-waypoint/scenarios                available scenarios and their parameters
POST   /v1/api-waypoint/scenarios                run one
DELETE /v1/api-waypoint/scenarios/{token}        undo a run
POST   /v1/api-waypoint/tokens                   mint a short-lived role token
```

Field schemas are JSON Schema draft 2020-12 plus two extension namespaces:

- **`x-laravel`** — the Laravel facts JSON Schema cannot express: `exists:`, `unique:`, conditional rules, the enum class, the PHP property name.
- **`x-faker`** — an abstract generation *strategy*. This package never names a generator library method; the Central App maps a strategy to whatever it generates with. That is what lets the two codebases be built independently.

The wire format is normative in `api-waypoint-contract.md`, and machine-checkable against `resources/schema/api-waypoint-1.0.json`. Both this package and the Central App test against that meta-schema.

---

## Adopting the query contract

Spatie Query Builder assembles its allowed lists inside a runtime method chain, so nothing can read them by reflection. Declare them once as a `QueryConfig` and build the query from the same object, and the description cannot drift from what the endpoint enforces.

**Before** — the allowed lists exist only at runtime, and the Postman collection is a guess:

```php
class ListOrders
{
    use AsAction;

    public function asController(Request $request)
    {
        return QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('reference'),
                AllowedFilter::partial('customer.name'),
                AllowedFilter::custom('placed_between', new PlacedBetweenFilter),
            ])
            ->allowedSorts(['placed_at', 'total_cents', 'reference'])
            ->defaultSort('-placed_at')
            ->allowedIncludes(['customer', 'lines', 'lines.product'])
            ->allowedFields(['orders.id', 'orders.reference', 'orders.status'])
            ->paginate(min($request->integer('per_page', 15), 100));
    }
}
```

**After** — one declaration, used by both the endpoint and the schema:

```php
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
```

That is fewer lines than before, the `per_page` ceiling is enforced rather than repeated, and the enum's cases become the filter's `allowed_values` in the document automatically.

---

## The four unmapped reasons, and how to fix each

An endpoint the compiler cannot fully describe is **still emitted** in `endpoints[]` with `"input": null`, so the Central App can list it as read-only, and is **simultaneously** listed in `diagnostics.unmapped_routes`. Nothing is silently dropped.

Every entry carries exactly one reason:

| Reason | What it means | How to fix it |
|---|---|---|
| `no_data_class` | The action takes no Spatie Data parameter and does not implement `ProvidesWaypointInput`. Usually an inline `$request->validate()`, which v1 does not introspect. | Type-hint a Data class on `handle()` / `asController()`, or implement `ProvidesWaypointInput::waypointInput()` and return the Data class FQCN. |
| `multipart` | The endpoint accepts file uploads, detected from an `UploadedFile`-typed property or a `file` / `image` / `mimes` rule. Out of scope for v1. | Split the upload into its own endpoint, or exclude the route in `routes.exclude`. The endpoint still appears, just without a body schema. |
| `closure_action` | The route action is a closure, so there is nothing to reflect. | Move the closure into an Action or controller class. |
| `unsupported_action` | The action class exists but could not be reflected, or a declared Data class could not be compiled. | Check the class is autoloadable and not abstract. If a Data class failed, look for an `uncompilable_data_class` warning naming the reason. |

A GET or DELETE with no Data class is **not** reported: there is no body to describe, so it is not a gap. That is what makes `--fail-on-unmapped` adoptable rather than something you switch off on day two.

### Escape hatches

When reflection cannot see the body, or sees the wrong thing:

```php
class ImportOrders implements ProvidesWaypointInput
{
    public static function waypointInput(): ?string
    {
        return ImportOrdersData::class;   // or null: "this endpoint takes no body"
    }
}
```

Returning `null` is a positive statement, not a gap, and is not reported.

Other declarations the compiler will not guess at:

```php
#[WaypointEndpoint(summary: 'Refund a paid order', roles: ['admin'])]
#[WaypointResponse(status: 202, transformer: RefundTransformer::class, errors: [409])]
#[WaypointPrecondition('Order must be in the paid state', scenario: 'paid_order')]
class RefundOrder { /* ... */ }
```

And per-property generation overrides:

```php
#[WaypointFaker(strategy: 'pattern', pattern: 'ORD-######', includeProbability: 0.6)]
public ?string $reference = null;
```

---

## Artisan commands

| Command | Behaviour |
|---|---|
| `waypoint:schema` | Compile and write the document. `--output=path` (default stdout), `--pretty`, `--clear` to bust the cache. |
| `waypoint:check` | Compile and report gaps and warnings. `--fail-on-unmapped`, `--fail-on-warning`, `--baseline=path`. |
| `waypoint:snapshot` | `--list` shows stored response snapshots and their age, `--prune` deletes them. |

`waypoint:check` works with the package **disabled**: only route registration is gated on `enabled`, never the compiler. CI never needs the HTTP surface switched on.

```yaml
- run: php artisan waypoint:check --fail-on-unmapped
- run: php artisan waypoint:check --baseline=storage/api-waypoint-baseline.json
```

The second is the "collection cannot go stale" enforcement: a PR that changes an endpoint must regenerate the committed baseline, and the diff shows exactly which endpoints and DTOs moved.

---

## Response snapshots

The compiler will not derive a response body schema from a Fractal transformer: `transform()` is arbitrary PHP, and a guessed shape is worse than an honest `"shape": "opaque"`. Instead, record a real one.

```php
// bootstrap/app.php, local only
$middleware->api(append: [RecordsWaypointResponses::class]);
```

```dotenv
API_WAYPOINT_SNAPSHOTS=true
```

Snapshots are sanitised with a recursive deny-list (`snapshots.redact`), truncated to 3 array elements and 500 characters, and rewritten at most once per TTL window. They never affect a hash, so recording one does not make the Central App report drift.

---

## Configuration

Every key is documented inline in `config/api-waypoint.php`. The ones worth knowing about:

| Key | Default | |
|---|---|---|
| `routes.include` / `routes.exclude` | `['api/*']` / waypoint, Sanctum, Horizon, Telescope | which routes are candidates |
| `routes.required_middleware` | `[]` | when non-empty, a route must carry one of these |
| `query.probe` | `false` | run `query()` methods to discover config. **Executes host code** |
| `faker.overrides` | `[]` | keyed `"Module.DataClass.property"` or `"*.property"` |
| `faker.default_include_probability` | `0.5` | chance an optional field is included in a generated payload |
| `references.redact` | password, remember_token, two_factor_secret, api_token | never read, labelled by or filtered on |
| `tokens.max_ttl_minutes` | `240` | requested TTLs are clamped to this |
| `scenarios` | `[]` | name => class implementing `WaypointScenario` |

---

## Development

```bash
composer install
composer test          # unit, feature and security suites
composer golden        # regenerate tests/Fixtures/golden.json, then read the diff
```

`workbench/` is a miniature host application with two modules and seven endpoints, covering every branch the pipeline has: a create with a nested collection, an index with a full query contract, a show with a route binding, a refund with an unresolvable field, a multipart upload, an inline-`validate()` action, and an unnamed closure route. The golden-file test asserts the whole compiled document in one assertion, and its fixture is what the Central App is built against.

## Releasing

Never tag locally. Releases are cut by the **Release** workflow, so that a version cannot exist without the full check suite having passed on it.

1. Move the shipping entries from `## [Unreleased]` into a new `## [x.y.z] - YYYY-MM-DD` section in `CHANGELOG.md`, and merge that to `main`.
2. Actions → **Release** → Run workflow, entering `x.y.z` (no leading `v`). Tick **dry-run** first if you want the checks without the tag.

The workflow refuses to continue unless the version is valid semver, has a matching `CHANGELOG.md` section, and has no existing tag. It then validates the manifest, runs Pint, PHPStan and the test suite, confirms the package autoloads with `--no-dev`, creates the annotated `vx.y.z` tag and opens a GitHub Release using that section as the notes.

`CHANGELOG.md` is the single place a version number lives. It is deliberately **not** in `composer.json`: Composer derives a package's version from its git tags, and `composer validate --strict` warns when a package published to Packagist carries a `version` field. That validation runs in CI, so adding one would fail the build.

## Licence

MIT.
