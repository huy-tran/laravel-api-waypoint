<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The index: every described endpoint, one line each.
 *
 * Exists so an agent can find the endpoint it needs without pulling the whole
 * compiled document into context, which for a real application is tens of
 * thousands of tokens of JSON Schema it did not ask for.
 */
#[IsReadOnly]
class WaypointEndpointsTool extends WaypointTool
{
    protected string $name = 'waypoint-endpoints';

    protected string $description = 'List the API endpoints described by API Waypoint: id, method, URI, module, auth, and whether each has an input schema or a query contract. Filter by module or a URI/id substring, or list only endpoints with no input schema. Use waypoint-endpoint for one endpoint in full.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()
                ->description('Only endpoints attributed to this module.'),
            'search' => $schema->string()
                ->description('Only endpoints whose id or URI contains this substring, case-insensitive.'),
            'unmapped_only' => $schema->boolean()
                ->description('Only endpoints with no input schema, each with the reason and its remedy.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $document = $this->document();
        $unmapped = $this->unmappedById($document);

        $module = $request->get('module');
        $search = $request->get('search');
        $unmappedOnly = (bool) $request->get('unmapped_only', false);

        $rows = [];

        foreach ($document['endpoints'] ?? [] as $endpoint) {
            $id = (string) $endpoint['id'];

            if (is_string($module) && $module !== '' && ($endpoint['module'] ?? null) !== $module) {
                continue;
            }

            if (is_string($search) && $search !== '' && ! $this->matches($endpoint, $search)) {
                continue;
            }

            if ($unmappedOnly && ! isset($unmapped[$id])) {
                continue;
            }

            $rows[] = $this->row($endpoint, $unmapped[$id] ?? null);
        }

        return Response::json([
            'schema_hash' => $document['schema_hash'] ?? null,
            'total_endpoints' => count($document['endpoints'] ?? []),
            'returned' => count($rows),
            'endpoints' => $rows,
        ]);
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    protected function matches(array $endpoint, string $search): bool
    {
        $needle = mb_strtolower($search);

        return str_contains(mb_strtolower((string) $endpoint['id']), $needle)
            || str_contains(mb_strtolower((string) $endpoint['uri']), $needle);
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed>|null $unmapped
     * @return array<string, mixed>
     */
    protected function row(array $endpoint, ?array $unmapped): array
    {
        $input = $endpoint['input'] ?? null;

        return array_filter([
            'id' => $endpoint['id'],
            'method' => $endpoint['method'],
            'uri' => $endpoint['uri'],
            'module' => $endpoint['module'] ?? null,
            'auth' => ($endpoint['auth']['required'] ?? false) === true
                ? (string) ($endpoint['auth']['scheme'] ?? 'required')
                : 'public',
            'input' => $input === null
                ? null
                : $this->componentName($input['schema']['$ref'] ?? null),
            'has_query_contract' => ($endpoint['query'] ?? null) !== null,
            'deprecated' => ($endpoint['deprecated'] ?? false) === true ? true : null,
            'unmapped_reason' => $unmapped['reason'] ?? null,
            'remedy' => $unmapped['detail'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }

    protected function componentName(?string $ref): ?string
    {
        $prefix = '#/components/data_objects/';

        return is_string($ref) && str_starts_with($ref, $prefix)
            ? substr($ref, strlen($prefix))
            : null;
    }
}
