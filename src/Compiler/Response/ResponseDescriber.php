<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Response;

use Hygo\ApiWaypoint\Attributes\WaypointResponse;
use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Data\DataSchemaCompiler;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use Hygo\ApiWaypoint\Compiler\Support\Diagnostics;
use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Compiler\Support\WarningCode;
use Hygo\ApiWaypoint\Contracts\ProvidesWaypointResponse;
use ReflectionNamedType;
use Spatie\LaravelData\Contracts\BaseData;
use Throwable;

/**
 * Describes what an endpoint returns.
 *
 * The compiler never guesses a transformer. A Fractal response is "opaque" and
 * says so; a Data-object response is introspectable and is compiled into
 * components, because Data output has a real structure worth diffing.
 */
class ResponseDescriber
{
    public const SHAPE_OPAQUE = 'opaque';

    public const SHAPE_COLLECTION = 'collection';

    public const SHAPE_DATA_OBJECT = 'data_object';

    public const SHAPE_EMPTY = 'empty';

    public function __construct(
        protected TransformerReader $transformers,
        protected SnapshotStore $snapshots,
        protected DataSchemaCompiler $dataSchemas,
        protected Diagnostics $diagnostics,
    ) {}

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function describe(
        CollectedRoute $route,
        ResolvedAction $action,
        string $endpointId,
        bool $hasInput,
        ?array $query,
        bool $authRequired,
    ): array {
        $attribute = $this->attribute($action);

        $transformer = $attribute->transformer ?? $this->fromContract($action);
        $status = $attribute->status ?? $this->contractStatus($action) ?? $this->inferStatus($route);
        $includes = $this->transformers->includes($transformer);

        $returnRef = $this->dataObjectReturn($action);
        $shape = $attribute->shape ?? $this->inferShape($route, $transformer, $returnRef, $status);

        if ($shape === self::SHAPE_OPAQUE && $transformer !== null && $this->snapshots->get($endpointId) === null) {
            $this->diagnostics->warn(
                WarningCode::OPAQUE_RESPONSE,
                'The Fractal response shape cannot be derived statically. Capture a snapshot to enable response diffing.',
                ['endpoint_id' => $endpointId]
            );
        }

        return array_filter([
            'success_status' => $status,
            'transformer' => $transformer,
            'serializer' => $attribute->serializer ?? $this->defaultSerializer($transformer),
            'shape' => $shape,
            'schema' => $returnRef !== null ? ['$ref' => $returnRef] : null,
            'available_includes' => $includes['available'],
            'default_includes' => $includes['default'],
            'snapshot' => $this->snapshots->forDocument($endpointId),
            'errors' => $this->errors($hasInput, $query !== null, $authRequired, $attribute),
        ], static fn ($value): bool => $value !== null);
    }

    protected function attribute(ResolvedAction $action): ?WaypointResponse
    {
        if ($action->reflection === null) {
            return null;
        }

        $attributes = $action->reflection->getAttributes(WaypointResponse::class);

        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance();
        } catch (Throwable) {
            return null;
        }
    }

    protected function fromContract(ResolvedAction $action): ?string
    {
        $class = $action->class;

        if ($class === null || ! is_a($class, ProvidesWaypointResponse::class, true)) {
            return null;
        }

        try {
            /** @var class-string<ProvidesWaypointResponse> $class */
            return $class::waypointTransformer();
        } catch (Throwable) {
            return null;
        }
    }

    protected function contractStatus(ResolvedAction $action): ?int
    {
        $class = $action->class;

        if ($class === null || ! is_a($class, ProvidesWaypointResponse::class, true)) {
            return null;
        }

        try {
            /** @var class-string<ProvidesWaypointResponse> $class */
            return $class::waypointSuccessStatus();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * store -> 201, destroy -> 204, everything else -> 200. Route name first,
     * because a POST that is not a create is common and the name usually says so.
     */
    protected function inferStatus(CollectedRoute $route): int
    {
        $name = (string) $route->name();

        if ($name !== '') {
            if (str_ends_with($name, '.store')) {
                return 201;
            }

            if (str_ends_with($name, '.destroy')) {
                return 204;
            }
        }

        return match ($route->method) {
            'POST' => $name === '' ? 201 : 200,
            'DELETE' => 204,
            default => 200,
        };
    }

    /**
     * A Data-typed return value is introspectable, so it is compiled into
     * components and referenced. Everything Fractal touches is opaque.
     */
    protected function dataObjectReturn(ResolvedAction $action): ?string
    {
        if ($action->reflection === null) {
            return null;
        }

        foreach (['asController', 'handle', '__invoke'] as $name) {
            if (! $action->reflection->hasMethod($name)) {
                continue;
            }

            $type = $action->reflection->getMethod($name)->getReturnType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (! class_exists($class) || ! is_a($class, BaseData::class, true)) {
                continue;
            }

            $component = $this->dataSchemas->compile($class);

            if ($component !== null) {
                return SchemaDocument::refFor($component);
            }
        }

        return null;
    }

    protected function inferShape(CollectedRoute $route, ?string $transformer, ?string $returnRef, int $status): string
    {
        if ($status === 204) {
            return self::SHAPE_EMPTY;
        }

        if ($returnRef !== null) {
            return self::SHAPE_DATA_OBJECT;
        }

        $name = (string) $route->name();

        if ($name !== '' && str_ends_with($name, '.index')) {
            return self::SHAPE_COLLECTION;
        }

        return $transformer !== null ? self::SHAPE_OPAQUE : self::SHAPE_OPAQUE;
    }

    protected function defaultSerializer(?string $transformer): ?string
    {
        if ($transformer === null) {
            return null;
        }

        $configured = config('fractal.default_serializer');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The documented error statuses, referenced into components.responses.
     *
     * Only the ones the endpoint can actually produce: no 422 on an endpoint with
     * no body, no 403 on an unauthenticated one.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function errors(bool $hasInput, bool $hasQuery, bool $authRequired, ?WaypointResponse $attribute): array
    {
        $statuses = [];

        if ($hasInput) {
            $statuses[422] = 'ValidationError';
        }

        if ($authRequired) {
            $statuses[401] = 'Unauthenticated';
            $statuses[403] = 'Forbidden';
        }

        if ($hasQuery) {
            $statuses[400] = 'QueryBuilderError';
        }

        foreach ($attribute->errors ?? [] as $status) {
            $statuses[(int) $status] = match ((int) $status) {
                400 => 'QueryBuilderError',
                401 => 'Unauthenticated',
                403 => 'Forbidden',
                404 => 'NotFound',
                409 => 'DomainConflict',
                422 => 'ValidationError',
                default => 'DomainConflict',
            };
        }

        ksort($statuses);

        $errors = [];

        foreach ($statuses as $status => $component) {
            $errors[] = [
                'status' => $status,
                'schema' => ['$ref' => '#/components/responses/'.$component],
            ];
        }

        return $errors;
    }
}
