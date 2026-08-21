<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Mcp\Tools;

use Hygo\ApiWaypoint\Support\SchemaRepository;
use Laravel\Mcp\Server\Tool;

/**
 * Shared plumbing for the read-only waypoint tools.
 *
 * These deliberately depend on the compiler and nothing else. The HTTP surface,
 * the shared secret and the enabled flag are all irrelevant here, so an agent gets
 * the same answers in a checkout where waypoint is switched off, and in CI.
 */
abstract class WaypointTool extends Tool
{
    public function __construct(protected SchemaRepository $schemas) {}

    /**
     * Recompile on every call, rather than reusing the memoised document.
     *
     * An agent's whole reason for asking is usually that it just changed
     * something. Handing back the document from before its edit would make the
     * tool worse than useless: confidently wrong.
     *
     * @return array<string, mixed>
     */
    protected function document(): array
    {
        return $this->schemas->fresh()->toArray();
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    protected function findEndpoint(array $document, string $id): ?array
    {
        foreach ($document['endpoints'] ?? [] as $endpoint) {
            if (($endpoint['id'] ?? null) === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * Endpoint ids indexed by the reason they carry no input schema.
     *
     * @param array<string, mixed> $document
     * @return array<string, array<string, mixed>>
     */
    protected function unmappedById(array $document): array
    {
        $indexed = [];

        foreach ($document['diagnostics']['unmapped_routes'] ?? [] as $unmapped) {
            $indexed[(string) $unmapped['endpoint_id']] = $unmapped;
        }

        return $indexed;
    }
}
