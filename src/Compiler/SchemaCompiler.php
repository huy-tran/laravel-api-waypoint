<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use Hygo\ApiWaypoint\Attributes\WaypointEndpoint;
use Hygo\ApiWaypoint\Attributes\WaypointPrecondition;
use Hygo\ApiWaypoint\Compiler\Data\CastInputTypes;
use Hygo\ApiWaypoint\Compiler\Data\ComponentRegistry;
use Hygo\ApiWaypoint\Compiler\Data\DataSchemaCompiler;
use Hygo\ApiWaypoint\Compiler\Data\RuleMapper;
use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\Input\InputResolver;
use Hygo\ApiWaypoint\Compiler\Query\QueryConfigExtractor;
use Hygo\ApiWaypoint\Compiler\Query\RecordingQueryBuilderSpy;
use Hygo\ApiWaypoint\Compiler\Response\ErrorResponses;
use Hygo\ApiWaypoint\Compiler\Response\ResponseDescriber;
use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\Response\TransformerReader;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Hygo\ApiWaypoint\Support\AppFingerprint;
use Hygo\ApiWaypoint\Support\ScenarioRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionNamedType;
use ReflectionType;
use Spatie\LaravelData\Support\DataConfig;
use Throwable;

/**
 * Runs the compilation pipeline and returns a finalised SchemaDocument.
 *
 * Every stage is constructed per compile rather than resolved as a singleton,
 * because the component registry and the diagnostics bag both carry per-run state
 * that must not leak from one compile into the next. Two compiles in the same
 * process must produce identical documents, and shared mutable state is the
 * easiest way to break that.
 */
class SchemaCompiler
{
    public function __construct(
        protected Application $app,
        protected Router $router,
    ) {}

    public function compile(): SchemaDocument
    {
        $startedAt = microtime(true);

        $config = (array) config('api-waypoint', []);
        $diagnostics = new Diagnostics;

        $modules = new ModuleResolver((string) ($config['default_module'] ?? 'app'));
        $actions = new ActionResolver;
        $registry = new ComponentRegistry;
        $snapshots = SnapshotStore::fromConfig((array) ($config['snapshots'] ?? []));
        $fakerHints = new FakerHintResolver((array) ($config['faker'] ?? []));

        $dataSchemas = new DataSchemaCompiler(
            $this->app->make(DataConfig::class),
            new RuleMapper,
            $fakerHints,
            $registry,
            $modules,
            $diagnostics,
        );

        $queries = new QueryConfigExtractor(
            $fakerHints,
            new RecordingQueryBuilderSpy,
            $diagnostics,
            (bool) Arr::get($config, 'query.probe', false),
        );

        $responses = new ResponseDescriber(
            new TransformerReader,
            $snapshots,
            $dataSchemas,
            $diagnostics,
        );

        /** @var InputResolver $inputs */
        $inputs = $this->app->make(InputResolver::class);

        $collected = (new RouteCollector($this->router))->collect((array) ($config['routes'] ?? []));
        $collected = $this->assignIds($collected, $actions, $modules, $diagnostics);

        $endpoints = [];
        $moduleCounts = [];
        $moduleNames = [];
        $schemes = [];

        foreach ($collected as $route) {
            [$endpoint, $module] = $this->compileEndpoint($route, $actions, $modules, $inputs, $dataSchemas, $queries, $responses, $diagnostics);

            $endpoints[] = $endpoint;

            $moduleCounts[$module['key']] = ($moduleCounts[$module['key']] ?? 0) + 1;
            // The declared name, not one reconstructed from the key: a module system
            // may spell it in a way Str::studly() would not recover.
            $moduleNames[$module['key']] = $module['name'];

            if (($scheme = $endpoint['auth']['scheme'] ?? null) !== null) {
                $schemes[$scheme] = true;
            }
        }

        $withSnapshots = count(array_filter(
            $endpoints,
            static fn (array $endpoint): bool => ($endpoint['response']['snapshot'] ?? null) !== null
        ));

        $diagnostics->set('routes_total', count($collected));
        $diagnostics->set('routes_mapped', count($collected) - count($diagnostics->unmappedRoutes()));
        $diagnostics->set('data_objects', $registry->count());
        $diagnostics->set('endpoints_with_snapshots', $withSnapshots);
        $diagnostics->set('compile_ms', (int) round((microtime(true) - $startedAt) * 1000));

        $scenarios = $this->app->make(ScenarioRegistry::class)->describe();

        return (new SchemaDocument(
            application: AppFingerprint::describe($this->apiPrefix($collected)),
            capabilities: $this->capabilities($config, $scenarios),
            auth: $this->authSchemes(array_keys($schemes), $config),
            modules: $this->modules($moduleNames, $moduleCounts),
            endpoints: $endpoints,
            dataObjects: $registry->all(),
            responses: ErrorResponses::all(),
            diagnostics: $diagnostics,
            generatedAt: now()->toIso8601String(),
        ))->finalise();
    }

    /**
     * Ids are assigned here rather than in the collector, because an unnamed
     * route's id is prefixed with its module, which needs the action resolved.
     *
     * @param array<int, CollectedRoute> $collected
     * @return array<int, CollectedRoute>
     */
    protected function assignIds(
        array $collected,
        ActionResolver $actions,
        ModuleResolver $modules,
        Diagnostics $diagnostics
    ): array {
        $seen = [];

        foreach ($collected as $route) {
            $id = $route->baseId;

            if (! $route->named) {
                $action = $actions->resolve($route->route);
                $module = $modules->resolve($action->class)['key'];
                $id = $module.'.'.$id;
            }

            // Two routes can still collide, most easily when both are unnamed and
            // their URIs slug to the same thing. Disambiguate rather than drop one.
            if (isset($seen[$id])) {
                $seen[$id]++;
                $id .= '_'.$seen[$id];
            } else {
                $seen[$id] = 1;
            }

            $route->id = $id;

            if (! $route->named) {
                $diagnostics->warn(
                    WarningCode::UNNAMED_ROUTE,
                    'Route has no name, so its id is derived from the URI and will move if the URI is refactored. '
                    .'The Central App keys saved requests on this id. Name the route.',
                    ['endpoint_id' => $id]
                );
            }
        }

        usort($collected, static fn (CollectedRoute $a, CollectedRoute $b): int => $a->id <=> $b->id);

        return $collected;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{key: string, name: string}}
     */
    protected function compileEndpoint(
        CollectedRoute $route,
        ActionResolver $actions,
        ModuleResolver $modules,
        InputResolver $inputs,
        DataSchemaCompiler $dataSchemas,
        QueryConfigExtractor $queries,
        ResponseDescriber $responses,
        Diagnostics $diagnostics,
    ): array {
        $action = $actions->resolve($route->route);
        $endpointAttribute = $this->endpointAttribute($action);

        $module = $endpointAttribute?->module !== null
            ? ['key' => Str::snake($endpointAttribute->module), 'name' => Str::studly($endpointAttribute->module)]
            : $modules->resolve($action->class);

        if ($action->isClosure()) {
            $diagnostics->warn(
                WarningCode::UNSUPPORTED_ACTION,
                'Route action is a closure and cannot be described.',
                ['endpoint_id' => $route->id]
            );
        }

        $auth = $this->auth($route, $endpointAttribute);
        $input = $this->input($route, $action, $inputs, $dataSchemas, $diagnostics);
        $query = $queries->extract($route, $action, $route->id);

        $response = $responses->describe(
            $route,
            $action,
            $route->id,
            $input !== null,
            $query,
            (bool) $auth['required'],
        );

        $endpoint = array_filter([
            'id' => $route->id,
            'hash' => null, // Assigned by SchemaDocument::finalise().
            'module' => $module['key'],
            'route_name' => $route->name(),
            'method' => $route->method,
            'uri' => $route->uri(),
            'path_parameters' => $this->pathParameters($route, $action),
            'summary' => $endpointAttribute->summary ?? $this->summary($route, $action),
            'deprecated' => $endpointAttribute->deprecated ?? false,
            'action' => $action->toArray(),
            'middleware' => $route->middleware(),
            'auth' => $auth,
            'input' => $input,
            'query' => $query,
            'response' => $response,
            'preconditions' => $this->preconditions($action) ?: null,
        ], static fn ($value, string $key): bool => $value !== null || in_array($key, ['hash', 'input', 'query', 'route_name'], true), ARRAY_FILTER_USE_BOTH);

        return [$endpoint, $module];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function input(
        CollectedRoute $route,
        ResolvedAction $action,
        InputResolver $inputs,
        DataSchemaCompiler $dataSchemas,
        Diagnostics $diagnostics
    ): ?array {
        $resolution = $inputs->resolve($route, $action);

        if (! $resolution->isMapped()) {
            if ($resolution->reason !== null) {
                $diagnostics->unmapped($route->id, $route->method, $route->uri(), $resolution->reason, [
                    'route_name' => $route->name(),
                    'action' => $action->class,
                    'detail' => $resolution->detail,
                ]);
            }

            return null;
        }

        /** @var string $dataClass */
        $dataClass = $resolution->dataClass;

        // Multipart is detected before compiling, so the document is not left
        // carrying a component nothing references.
        if ($this->acceptsUploads($dataClass)) {
            $diagnostics->unmapped($route->id, $route->method, $route->uri(), UnmappedReason::MULTIPART, [
                'route_name' => $route->name(),
                'action' => $action->class,
                'detail' => sprintf('[%s] accepts an uploaded file. Multipart bodies are out of scope for waypoint v1.', $dataClass),
            ]);

            $diagnostics->warn(
                WarningCode::MULTIPART_ENDPOINT,
                'Endpoint accepts file uploads, which v1 does not describe.',
                ['endpoint_id' => $route->id]
            );

            return null;
        }

        $component = $dataSchemas->compile($dataClass);

        if ($component === null) {
            $diagnostics->unmapped($route->id, $route->method, $route->uri(), UnmappedReason::UNSUPPORTED_ACTION, [
                'route_name' => $route->name(),
                'action' => $action->class,
                'detail' => sprintf('[%s] could not be compiled into a schema.', $dataClass),
            ]);

            return null;
        }

        // A GET or DELETE body is legal but vanishingly rare; the payload is the
        // query string, and saying "body" would make the Central App send one.
        $location = in_array($route->method, ['GET', 'DELETE'], true) ? 'query' : 'body';

        return [
            'location' => $location,
            'content_type' => 'application/json',
            'data_class' => $dataClass,
            'schema' => ['$ref' => SchemaDocument::refFor($component)],
        ];
    }

    /**
     * A Data class holding an UploadedFile, or declaring a file rule, means the
     * endpoint is multipart.
     */
    protected function acceptsUploads(string $dataClass): bool
    {
        try {
            $dataClassInfo = $this->app->make(DataConfig::class)->getDataClass($dataClass);
        } catch (Throwable) {
            return false;
        }

        foreach ($dataClassInfo->properties as $property) {
            foreach (array_keys($property->type->type->getAcceptedTypes()) as $type) {
                if (CastInputTypes::isUpload($type)) {
                    return true;
                }
            }
        }

        try {
            $rules = $dataClass::getValidationRules([]);
        } catch (Throwable) {
            return false;
        }

        foreach ($rules as $propertyRules) {
            foreach ((array) $propertyRules as $rule) {
                if (! is_string($rule)) {
                    continue;
                }

                $name = strtolower(explode(':', $rule, 2)[0]);

                if (in_array($name, ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function pathParameters(CollectedRoute $route, ResolvedAction $action): array
    {
        $uri = $route->route->uri();
        $parameters = [];

        foreach ($route->route->parameterNames() as $name) {
            $required = ! str_contains($uri, '{'.$name.'?}');
            $model = $this->boundModel($route, $action, $name);
            $key = $route->route->bindingFieldFor($name) ?? $this->routeKeyName($model);

            $schema = $this->pathParameterSchema($key, $model);

            $parameter = array_filter([
                'name' => $name,
                'required' => $required,
                'binding' => $model !== null ? ['model' => $model, 'key' => $key] : null,
                'schema' => $schema,
            ], static fn ($value): bool => $value !== null);

            $parameter['x-faker'] = $model !== null
                ? [
                    'strategy' => 'reference',
                    'reference' => ['table' => $this->tableFor($model), 'column' => $key],
                ]
                : $this->unboundParameterHint($schema, $name);

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function unboundParameterHint(array $schema, string $name): array
    {
        return match ($schema['format'] ?? $schema['type'] ?? null) {
            'uuid' => ['strategy' => 'uuid'],
            'integer' => ['strategy' => 'int', 'min' => 1, 'max' => 1000],
            default => [
                'strategy' => 'unresolvable',
                'reason' => sprintf('[%s] is not a model binding, so no source of real values is known.', $name),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function pathParameterSchema(string $key, ?string $model): array
    {
        if (str_contains($key, 'uuid')) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        if ($key === 'ulid') {
            return ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$'];
        }

        if ($model !== null && $key === 'id') {
            return ['type' => $this->modelKeyIsNumeric($model) ? 'integer' : 'string'];
        }

        return ['type' => $key === 'id' ? 'integer' : 'string'];
    }

    /**
     * The model a path parameter is bound to, if any.
     *
     * Two sources, because the route's own signature is not always the useful one:
     * an Action routed as MyAction::class registers as Class@__invoke, whose
     * signature is a variadic mixed, and laravel-actions only rewrites it to
     *
     * @asController when its provider booted before the routes were registered.
     * Reflecting the action's entry point directly does not care about that
     * ordering, so the compiler produces the same document either way.
     */
    protected function boundModel(CollectedRoute $route, ResolvedAction $action, string $name): ?string
    {
        foreach ($route->route->signatureParameters() as $parameter) {
            if ($parameter->getName() === $name && ($model = $this->modelType($parameter->getType())) !== null) {
                return $model;
            }
        }

        if ($action->reflection === null) {
            return null;
        }

        foreach (['asController', 'handle'] as $entryPoint) {
            if (! $action->reflection->hasMethod($entryPoint)) {
                continue;
            }

            foreach ($action->reflection->getMethod($entryPoint)->getParameters() as $parameter) {
                if ($parameter->getName() === $name && ($model = $this->modelType($parameter->getType())) !== null) {
                    return $model;
                }
            }
        }

        // Last resort: a single-model entry point whose parameter is named
        // differently from the URI segment still tells us what the binding is.
        return $this->soleModelParameter($action, count($route->route->parameterNames()));
    }

    protected function modelType(?ReflectionType $type): ?string
    {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return is_a($type->getName(), Model::class, true) ? $type->getName() : null;
    }

    protected function soleModelParameter(ResolvedAction $action, int $parameterCount): ?string
    {
        if ($parameterCount !== 1 || $action->reflection === null) {
            return null;
        }

        foreach (['asController', 'handle'] as $entryPoint) {
            if (! $action->reflection->hasMethod($entryPoint)) {
                continue;
            }

            $models = [];

            foreach ($action->reflection->getMethod($entryPoint)->getParameters() as $parameter) {
                if (($model = $this->modelType($parameter->getType())) !== null) {
                    $models[] = $model;
                }
            }

            if (count($models) === 1) {
                return $models[0];
            }
        }

        return null;
    }

    protected function routeKeyName(?string $model): string
    {
        if ($model === null) {
            return 'id';
        }

        try {
            /** @var Model $instance */
            $instance = new $model;

            return $instance->getRouteKeyName();
        } catch (Throwable) {
            return 'id';
        }
    }

    protected function tableFor(string $model): string
    {
        try {
            /** @var Model $instance */
            $instance = new $model;

            return $instance->getTable();
        } catch (Throwable) {
            return Str::snake(Str::pluralStudly(class_basename($model)));
        }
    }

    protected function modelKeyIsNumeric(string $model): bool
    {
        try {
            /** @var Model $instance */
            $instance = new $model;

            return $instance->getIncrementing() && in_array($instance->getKeyType(), ['int', 'integer'], true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function auth(CollectedRoute $route, ?WaypointEndpoint $attribute): array
    {
        $middleware = $route->middleware();
        $guard = null;
        $required = false;
        $abilities = $attribute->abilities ?? [];
        $roles = $attribute->roles ?? [];

        foreach ($middleware as $applied) {
            [$name, $arguments] = $this->splitMiddleware($applied);

            match (true) {
                $name === 'auth', $name === 'auth.basic' => [$required = true, $guard ??= $arguments[0] ?? 'web'],
                $name === 'auth.sanctum' => [$required = true, $guard = 'sanctum'],
                $name === 'abilities', $name === 'ability' => $abilities = array_merge($abilities, $arguments),
                $name === 'can' => $abilities = array_merge($abilities, array_slice($arguments, 0, 1)),
                $name === 'role', $name === 'hasrole' => $roles = array_merge($roles, $this->splitList($arguments)),
                default => null,
            };
        }

        return [
            'required' => $required,
            'scheme' => $required ? $this->schemeFor($guard) : null,
            'guard' => $guard,
            'abilities' => array_values(array_unique($abilities)),
            'roles' => array_values(array_unique($roles)),
        ];
    }

    /**
     * @param array<int, string> $arguments
     * @return array<int, string>
     */
    protected function splitList(array $arguments): array
    {
        $values = [];

        foreach ($arguments as $argument) {
            foreach (explode('|', $argument) as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    protected function splitMiddleware(string $middleware): array
    {
        if (! str_contains($middleware, ':')) {
            return [strtolower($middleware), []];
        }

        [$name, $arguments] = explode(':', $middleware, 2);

        return [strtolower($name), array_map('trim', explode(',', $arguments))];
    }

    protected function schemeFor(?string $guard): string
    {
        return match ($guard) {
            'sanctum' => 'sanctum_bearer',
            'api' => 'api_bearer',
            null, 'web' => 'session',
            default => $guard.'_guard',
        };
    }

    /**
     * @param array<int, string> $used
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function authSchemes(array $used, array $config): array
    {
        sort($used);

        $catalogue = [
            'sanctum_bearer' => [
                'id' => 'sanctum_bearer',
                'type' => 'http',
                'scheme' => 'bearer',
                'header' => 'Authorization',
                'description' => 'Sanctum personal access token. Mint one via POST /'
                    .trim((string) ($config['prefix'] ?? '_api-waypoint'), '/').'/tokens.',
            ],
            'api_bearer' => [
                'id' => 'api_bearer',
                'type' => 'http',
                'scheme' => 'bearer',
                'header' => 'Authorization',
                'description' => 'Bearer token for the api guard.',
            ],
            'session' => [
                'id' => 'session',
                'type' => 'cookie',
                'scheme' => 'session',
                'header' => 'Cookie',
                'description' => 'Session cookie authentication.',
            ],
        ];

        $schemes = [];

        foreach ($used as $id) {
            $schemes[] = $catalogue[$id] ?? [
                'id' => $id,
                'type' => 'http',
                'scheme' => 'bearer',
                'header' => 'Authorization',
                'description' => 'Custom guard.',
            ];
        }

        $roles = array_keys((array) Arr::get($config, 'tokens.roles', []));
        sort($roles);

        return [
            'schemes' => $schemes,
            'test_roles' => $roles,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $scenarios
     * @return array<string, bool>
     */
    protected function capabilities(array $config, array $scenarios): array
    {
        return [
            'references' => true,
            'tokens' => (bool) Arr::get($config, 'tokens.enabled', true)
                && Arr::get($config, 'tokens.roles', []) !== []
                && class_exists(Sanctum::class),
            'scenarios' => $scenarios !== [],
            'reset' => false,
            'response_snapshots' => (bool) Arr::get($config, 'snapshots.enabled', false),
        ];
    }

    /**
     * @param array<string, string> $names
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    protected function modules(array $names, array $counts): array
    {
        ksort($counts);

        $described = [];

        foreach ($counts as $key => $count) {
            $described[] = [
                'key' => $key,
                'name' => $names[$key] ?? Str::studly($key),
                'endpoint_count' => $count,
            ];
        }

        return $described;
    }

    /**
     * The longest path prefix every collected route shares, which is what the
     * Central App shows as the API root.
     *
     * @param array<int, CollectedRoute> $collected
     */
    protected function apiPrefix(array $collected): ?string
    {
        if ($collected === []) {
            return null;
        }

        $segments = null;

        foreach ($collected as $route) {
            $parts = explode('/', trim($route->route->uri(), '/'));

            // A parameter segment is not part of a prefix.
            $literal = [];
            foreach ($parts as $part) {
                if (str_starts_with($part, '{')) {
                    break;
                }

                $literal[] = $part;
            }

            if ($segments === null) {
                $segments = $literal;

                continue;
            }

            $common = [];
            foreach ($segments as $index => $segment) {
                if (($literal[$index] ?? null) !== $segment) {
                    break;
                }

                $common[] = $segment;
            }

            $segments = $common;
        }

        return $segments === [] ? null : '/'.implode('/', $segments);
    }

    protected function endpointAttribute(ResolvedAction $action): ?WaypointEndpoint
    {
        if ($action->reflection === null) {
            return null;
        }

        $attributes = $action->reflection->getAttributes(WaypointEndpoint::class);

        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function preconditions(ResolvedAction $action): array
    {
        if ($action->reflection === null) {
            return [];
        }

        $described = [];

        foreach ($action->reflection->getAttributes(WaypointPrecondition::class) as $attribute) {
            try {
                $described[] = $attribute->newInstance()->toArray();
            } catch (Throwable) {
                continue;
            }
        }

        return $described;
    }

    /**
     * The action's docblock summary, then a humanised route name. Both beat an
     * empty string in a list of two hundred endpoints.
     */
    protected function summary(CollectedRoute $route, ResolvedAction $action): ?string
    {
        if ($action->reflection !== null) {
            $doc = $action->reflection->getDocComment();

            if (is_string($doc)) {
                foreach (explode("\n", $doc) as $line) {
                    $line = trim(ltrim(trim($line), '/*'));

                    if ($line !== '' && ! str_starts_with($line, '@')) {
                        return $line;
                    }
                }
            }
        }

        $name = (string) $route->name();

        if ($name === '') {
            return null;
        }

        $segments = explode('.', $name);
        $verb = array_pop($segments);
        $subject = $segments === [] ? '' : end($segments);

        $verbs = [
            'index' => 'List',
            'store' => 'Create',
            'show' => 'Show',
            'update' => 'Update',
            'destroy' => 'Delete',
        ];

        return isset($verbs[$verb]) && $subject !== ''
            ? $verbs[$verb].' '.str_replace('_', ' ', Str::snake($subject))
            : Str::headline(str_replace('.', ' ', $name));
    }
}
