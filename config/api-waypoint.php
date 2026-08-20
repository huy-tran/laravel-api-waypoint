<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Defaults to false. The HTTP surface is registered only when this is true,
    | the current environment is listed below, and a secret is set. The Artisan
    | compiler (waypoint:schema / waypoint:check) works regardless, so CI never
    | needs the HTTP surface switched on.
    |
    | THIS MUST NEVER BE TRUE IN PRODUCTION.
    |
    */

    'enabled' => env('API_WAYPOINT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Permitted environments
    |--------------------------------------------------------------------------
    |
    | Routes register only when app()->environment() is in this list. Listing
    | "production" is a hard error: the service provider throws rather than
    | registering. Add "staging" only for a genuinely non-customer-facing app.
    |
    */

    'environments' => ['local'],

    /*
    |--------------------------------------------------------------------------
    | Shared secret
    |--------------------------------------------------------------------------
    |
    | Sent by the Central App as the X-Api-Waypoint-Secret header and compared
    | with hash_equals(). No default: an empty secret means no routes at all.
    | Generate with: php -r "echo bin2hex(random_bytes(32));"
    |
    */

    'secret' => env('API_WAYPOINT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('API_WAYPOINT_PREFIX', 'v1/api-waypoint'),

    /*
    |--------------------------------------------------------------------------
    | Audit log channel
    |--------------------------------------------------------------------------
    |
    | Every waypoint request writes one line here: route, secret fingerprint,
    | and the resolved scenario/role where relevant.
    |
    */

    'log_channel' => env('API_WAYPOINT_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Fallback module key
    |--------------------------------------------------------------------------
    |
    | Used when an action cannot be attributed to a module.
    |
    */

    'default_module' => 'app',

    /*
    |--------------------------------------------------------------------------
    | Cache store for compiled documents
    |--------------------------------------------------------------------------
    |
    | Compilation runs on every request in "local" (correctness over speed).
    | In any other permitted environment the document is cached in this store.
    | null uses the application default store. Bust it with waypoint:schema --clear.
    |
    */

    'cache' => [
        'store' => null,
        'ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route collection
    |--------------------------------------------------------------------------
    |
    | include              URI patterns (Str::is) that make a route a candidate.
    | exclude              URI patterns that veto a route, applied after include.
    | required_middleware  When non-empty, a route must carry at least one of
    |                      these middleware names to be collected.
    |
    */

    'routes' => [
        'include' => ['api/*'],
        'exclude' => [
            'api/v1/api-waypoint*',
            'v1/api-waypoint*',
            'sanctum/*',
            'horizon/*',
            'telescope/*',
            '_debugbar/*',
            '_ignition/*',
        ],
        'required_middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query contract extraction
    |--------------------------------------------------------------------------
    |
    | probe: when true, Actions exposing a public query() method are called with
    | a recording spy to discover their Spatie Query Builder configuration.
    |
    | THIS EXECUTES HOST CODE. Leave it off unless every query() method in the
    | application is free of side effects. Probed configuration is reported with
    | x-laravel.query_source = "probe" and a probed_query_config warning.
    |
    */

    'query' => [
        'probe' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload generation hints
    |--------------------------------------------------------------------------
    |
    | overrides   Keyed by "Module.DataClass.property" or "*.property".
    | name_hints  Property name => x-faker block. Merged over the shipped
    |             defaults in StrategyVocabulary::defaultNameHints().
    |
    */

    'faker' => [
        'default_include_probability' => 0.5,
        'array_count_ceiling' => 3,
        'overrides' => [
            // '*.email' => ['strategy' => 'internet.email'],
        ],
        'name_hints' => [
            // 'crn' => ['strategy' => 'pattern', 'mask' => '###-###'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference lookups
    |--------------------------------------------------------------------------
    |
    | GET /references/{table}/{column} is whitelisted by the compiled schema:
    | only (table, column) pairs appearing in an exists: or unique: rule are
    | reachable. "extra" adds pairs the schema cannot know about.
    |
    | redact          Columns never returned as a value, label or context entry.
    | scenario_hints  table => scenario name, surfaced when a lookup is empty.
    |
    */

    'references' => [
        'extra' => [
            // ['table' => 'countries', 'column' => 'code', 'label' => 'name'],
        ],
        'redact' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token'],
        'scenario_hints' => [
            // 'orders' => 'paid_order',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token minting
    |--------------------------------------------------------------------------
    |
    | Only role names declared here are accepted. Each resolver returns the
    | dedicated waypoint user for that role; its email must match
    | email_pattern so waypoint users stay identifiable and prunable.
    |
    */

    'tokens' => [
        'enabled' => true,
        'max_ttl_minutes' => 240,
        'default_ttl_minutes' => 60,
        'email_pattern' => 'waypoint+{role}@{host}',
        'guard' => 'sanctum',
        'roles' => [
            // 'admin' => ['abilities' => ['*'], 'resolver' => App\Waypoint\AdminWaypointUser::class],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scenarios
    |--------------------------------------------------------------------------
    |
    | name => class implementing Hygo\ApiWaypoint\Contracts\WaypointScenario.
    | POST /scenarios accepts a declared name and that scenario's own validated
    | parameters. There is no code path accepting a class or factory name.
    |
    */

    'scenarios' => [
        // 'paid_order' => Modules\Orders\Waypoint\Scenarios\PaidOrder::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response snapshots
    |--------------------------------------------------------------------------
    |
    | When enabled, RecordsWaypointResponses stores a sanitised, truncated copy
    | of successful responses under storage/app/api-waypoint/snapshots.
    |
    */

    'snapshots' => [
        'enabled' => env('API_WAYPOINT_SNAPSHOTS', false),
        'ttl_days' => 30,
        'disk' => 'local',
        'path' => 'api-waypoint/snapshots',
        'max_string_length' => 500,
        'max_array_items' => 3,
        'redact' => ['password', 'token', 'secret', 'authorization', 'card', 'iban', 'tfn', 'abn'],
    ],
];
