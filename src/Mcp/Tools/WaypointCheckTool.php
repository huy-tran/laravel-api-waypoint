<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The gap list, as a worklist rather than as terminal output.
 *
 * Same compile as waypoint:check, shaped for something that is going to act on it:
 * every unmapped route carries the remedy for its reason, so the next step is
 * named rather than inferred.
 */
#[IsReadOnly]
class WaypointCheckTool extends WaypointTool
{
    /** Per warning code, so one noisy code cannot crowd out the rest. */
    private const WARNINGS_PER_CODE = 10;

    protected string $name = 'waypoint-check';

    protected string $description = 'Compile the API Waypoint schema and report what it cannot describe: endpoints with no input schema (each with its reason and remedy), diagnostic warnings grouped by code, and the document hash. Run this after changing a route, a Spatie Data class, or a query contract. Needs neither the HTTP surface nor the shared secret.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()
                ->description('Only unmapped routes with this reason: no_data_class, multipart, closure_action or unsupported_action.'),
            'warnings' => $schema->boolean()
                ->description('Include diagnostic warnings. Defaults to true.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $document = $this->document();
        $diagnostics = $document['diagnostics'];

        $reason = $request->get('reason');
        $unmapped = $diagnostics['unmapped_routes'];

        if (is_string($reason) && $reason !== '') {
            $unmapped = array_values(array_filter(
                $unmapped,
                static fn (array $route): bool => $route['reason'] === $reason
            ));
        }

        $payload = [
            'schema_hash' => $document['schema_hash'] ?? null,
            'counts' => $diagnostics['counts'],
            'unmapped_routes' => array_map($this->unmappedRoute(...), $unmapped),
        ];

        if ($request->get('warnings', true) !== false) {
            $payload['warnings'] = $this->groupWarnings($diagnostics['warnings']);
        }

        // A GET or DELETE with no body is not a gap, so "nothing here" is a real
        // result and worth stating rather than leaving as an empty array.
        $payload['verdict'] = $unmapped === []
            ? 'Every collected route has an input schema, or does not need one.'
            : sprintf('%d route(s) have no input schema.', count($unmapped));

        return Response::json($payload);
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    protected function unmappedRoute(array $route): array
    {
        return [
            'endpoint_id' => $route['endpoint_id'],
            'method' => $route['method'],
            'uri' => $route['uri'],
            'reason' => $route['reason'],
            'remedy' => $route['detail'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    protected function groupWarnings(array $warnings): array
    {
        $grouped = [];

        foreach ($warnings as $warning) {
            $grouped[(string) $warning['code']][] = array_filter([
                'endpoint_id' => $warning['endpoint_id'] ?? null,
                'component' => $warning['component'] ?? null,
                'property' => $warning['property'] ?? null,
                'detail' => $warning['detail'],
            ], static fn ($value): bool => $value !== null);
        }

        ksort($grouped);

        $summarised = [];

        foreach ($grouped as $code => $entries) {
            $summarised[$code] = [
                'count' => count($entries),
                'entries' => array_slice($entries, 0, self::WARNINGS_PER_CODE),
            ];

            if (count($entries) > self::WARNINGS_PER_CODE) {
                $summarised[$code]['omitted'] = count($entries) - self::WARNINGS_PER_CODE;
            }
        }

        return $summarised;
    }
}
