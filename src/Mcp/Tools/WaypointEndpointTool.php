<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One endpoint, with every component it references resolved alongside it.
 *
 * The document stores input schemas as $refs into components.data_objects, so the
 * raw endpoint entry on its own says almost nothing about the request body. Chasing
 * those refs is mechanical, and making an agent do it by fetching the whole
 * document defeats the point of having an index.
 */
#[IsReadOnly]
class WaypointEndpointTool extends WaypointTool
{
    protected string $name = 'waypoint-endpoint';

    protected string $description = 'Describe one API endpoint in full: its input schema with every referenced Data class resolved, its query contract (filters, sorts, includes, pagination), path parameters, auth requirements, response shape and error responses. Takes the endpoint id from waypoint-endpoints. Use this before writing or changing a request to an endpoint.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The endpoint id, for example "orders.store". List them with waypoint-endpoints.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = (string) $request->get('id');
        $document = $this->document();
        $endpoint = $this->findEndpoint($document, $id);

        if ($endpoint === null) {
            return Response::error(sprintf(
                'No endpoint with id [%s]. List the available ids with waypoint-endpoints.',
                $id
            ));
        }

        $unmapped = $this->unmappedById($document)[$id] ?? null;
        $components = $this->resolveComponents($document, $endpoint);

        return Response::json(array_filter([
            'endpoint' => $endpoint,
            'components' => $components,
            'unmapped' => $unmapped === null ? null : [
                'reason' => $unmapped['reason'],
                'remedy' => $unmapped['detail'],
            ],
            'warnings' => $this->warningsFor(
                $document,
                $id,
                array_keys($components['data_objects'] ?? [])
            ),
        ], static fn ($value): bool => $value !== null && $value !== []));
    }

    /**
     * Every data object and response component reachable from this endpoint.
     *
     * Transitive, because a Data class holding a collection of another Data class
     * only carries a $ref to it, and an agent writing a payload needs the whole
     * tree, not its root.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $endpoint
     * @return array<string, array<string, mixed>>
     */
    protected function resolveComponents(array $document, array $endpoint): array
    {
        $dataObjects = [];
        $responses = [];
        $pending = $this->refsIn($endpoint);

        while ($pending !== []) {
            $ref = array_shift($pending);
            [$bucket, $name] = $ref;

            if ($bucket === 'data_objects') {
                if (isset($dataObjects[$name])) {
                    continue;
                }

                $component = $document['components']['data_objects'][$name] ?? null;

                if ($component === null) {
                    continue;
                }

                $dataObjects[$name] = $component;
                $pending = array_merge($pending, $this->refsIn($component));

                continue;
            }

            if (isset($responses[$name])) {
                continue;
            }

            $component = $document['components']['responses'][$name] ?? null;

            if ($component !== null) {
                $responses[$name] = $component;
            }
        }

        ksort($dataObjects);
        ksort($responses);

        return array_filter([
            'data_objects' => $dataObjects,
            'responses' => $responses,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{0: string, 1: string}>
     */
    protected function refsIn(array $node): array
    {
        $found = [];

        array_walk_recursive($node, static function ($value, $key) use (&$found): void {
            if ($key !== '$ref' || ! is_string($value)) {
                return;
            }

            foreach (['data_objects', 'responses'] as $bucket) {
                $prefix = '#/components/'.$bucket.'/';

                if (str_starts_with($value, $prefix)) {
                    $found[] = [$bucket, substr($value, strlen($prefix))];
                }
            }
        });

        return $found;
    }

    /**
     * Warnings about this endpoint, and about any component it resolved.
     *
     * A rule the compiler could not describe is recorded against the component it
     * lives on, not against the endpoints that send it. Without the second half of
     * this, "why is this field missing from the schema" would have no answer here.
     *
     * @param array<string, mixed> $document
     * @param array<int, string> $components
     * @return array<int, array<string, mixed>>
     */
    protected function warningsFor(array $document, string $id, array $components): array
    {
        $warnings = [];

        foreach ($document['diagnostics']['warnings'] ?? [] as $warning) {
            $matchesEndpoint = ($warning['endpoint_id'] ?? null) === $id;
            $matchesComponent = isset($warning['component'])
                && in_array($warning['component'], $components, true);

            if ($matchesEndpoint || $matchesComponent) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }
}
