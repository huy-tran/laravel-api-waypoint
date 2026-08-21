<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Support;

use JsonSerializable;

/**
 * The compiled document, and the single place hashes are computed.
 *
 * Both GET / and GET /manifest are built from this object, never from a separate
 * cheaper path, so the two can never disagree.
 *
 * Field names here are normative and come from
 * resources/schema/api-waypoint-1.0.json, which the conformance test validates
 * the compiled document against.
 */
class SchemaDocument implements JsonSerializable
{
    public const FORMAT = '1.0';

    /**
     * Volatile fields stripped before any hash is taken. Without these, every
     * recompile produces a new hash and the Central App reports drift forever.
     */
    private const VOLATILE = ['generated_at', 'schema_hash', 'hash', 'snapshot', 'compile_ms'];

    /**
     * @param array<string, mixed> $application
     * @param array<string, bool> $capabilities
     * @param array<string, mixed> $auth
     * @param array<int, array<string, mixed>> $modules
     * @param array<int, array<string, mixed>> $endpoints
     * @param array<string, array<string, mixed>> $dataObjects
     * @param array<string, array<string, mixed>> $responses
     */
    public function __construct(
        protected array $application,
        protected array $capabilities,
        protected array $auth,
        protected array $modules,
        protected array $endpoints,
        protected array $dataObjects,
        protected array $responses,
        protected Diagnostics $diagnostics,
        protected string $generatedAt,
        protected ?string $schemaHash = null,
    ) {}

    /**
     * Compute data-object, endpoint and document hashes, in that order: an endpoint
     * hash depends on the hash of the component it references, and the document
     * hash depends on both.
     */
    public function finalise(): self
    {
        $this->hashComponents();

        ksort($this->dataObjects);

        foreach ($this->endpoints as $index => $endpoint) {
            $this->endpoints[$index]['hash'] = $this->hashEndpoint($endpoint);
        }

        usort($this->endpoints, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $this->schemaHash = CanonicalHasher::hash(
            CanonicalHasher::without($this->bodyForHashing(), self::VOLATILE)
        );

        return $this;
    }

    /**
     * Component hashes, computed transitively.
     *
     * A component's own schema only carries a $ref to a nested Data class, so a
     * change inside that nested class would not move the parent's hash on its own.
     * It has to: an endpoint that sends a CreateOrderData is sending OrderLineData
     * objects inside it, and its payload shape genuinely changed. Folding the
     * referenced components' hashes in is what makes "here are the nine endpoints
     * affected by this DTO change" answerable.
     */
    protected function hashComponents(): void
    {
        $local = [];
        $references = [];

        foreach ($this->dataObjects as $name => $component) {
            unset($component['x-laravel']['hash']);

            $local[$name] = CanonicalHasher::hash(CanonicalHasher::without($component, self::VOLATILE));
            $references[$name] = $this->referencedComponents($component);
        }

        $effective = [];

        foreach (array_keys($this->dataObjects) as $name) {
            $this->dataObjects[$name]['x-laravel']['hash'] = $this->effectiveHash(
                $name,
                $local,
                $references,
                $effective,
                []
            );
        }
    }

    /**
     * @param array<string, string> $local
     * @param array<string, array<int, string>> $references
     * @param array<string, string> $effective
     * @param array<int, string> $stack
     */
    protected function effectiveHash(string $name, array $local, array $references, array &$effective, array $stack): string
    {
        if (isset($effective[$name])) {
            return $effective[$name];
        }

        // A self-referencing Data class would otherwise recurse forever. Its own
        // hash already covers everything that can change about it.
        if (in_array($name, $stack, true)) {
            return $local[$name] ?? '';
        }

        $stack[] = $name;
        $parts = [$local[$name] ?? ''];

        foreach ($references[$name] ?? [] as $reference) {
            $parts[] = $reference.'='.$this->effectiveHash($reference, $local, $references, $effective, $stack);
        }

        // Only memoise a hash computed without a cycle cut, or the cut value leaks
        // into components that could have resolved fully.
        $hash = CanonicalHasher::hash($parts);

        if (count($stack) === 1) {
            $effective[$name] = $hash;
        }

        return $hash;
    }

    /**
     * Every data-object component referenced anywhere inside a schema, sorted.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    protected function referencedComponents(array $node): array
    {
        $found = [];

        array_walk_recursive($node, function ($value, $key) use (&$found): void {
            if ($key !== '$ref' || ! is_string($value)) {
                return;
            }

            if (($component = $this->componentNameFromRef($value)) !== null) {
                $found[$component] = true;
            }
        });

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * Endpoint hash inputs, per spec 5.11: method, uri, module, auth, path
     * parameters, the hash of the referenced input component, query and
     * success_status. Deliberately excludes snapshots, diagnostics, summaries and
     * anything time-dependent, so an endpoint only churns when its contract does.
     *
     * @param array<string, mixed> $endpoint
     */
    protected function hashEndpoint(array $endpoint): string
    {
        return CanonicalHasher::hash([
            'id' => $endpoint['id'],
            'method' => $endpoint['method'],
            'uri' => $endpoint['uri'],
            'module' => $endpoint['module'] ?? null,
            'auth' => $endpoint['auth'] ?? null,
            'path_parameters' => array_map(
                static fn (array $parameter): array => [
                    'name' => $parameter['name'],
                    'required' => $parameter['required'],
                    'schema' => $parameter['schema'] ?? null,
                ],
                $endpoint['path_parameters'] ?? []
            ),
            'input' => $this->inputHashInput($endpoint),
            'query' => $endpoint['query'] ?? null,
            'success_status' => $endpoint['response']['success_status'] ?? null,
        ]);
    }

    /**
     * The input contributes its referenced component's hash, not the whole schema.
     * That is what makes acceptance criterion 4 hold: changing one Data class moves
     * exactly the endpoints that reference it, and nothing else.
     *
     * @param array<string, mixed> $endpoint
     * @return array<string, mixed>|null
     */
    protected function inputHashInput(array $endpoint): ?array
    {
        $input = $endpoint['input'] ?? null;

        if ($input === null) {
            return null;
        }

        $component = $this->componentNameFromRef($input['schema']['$ref'] ?? null);

        return [
            'location' => $input['location'] ?? null,
            'content_type' => $input['content_type'] ?? null,
            'component' => $component,
            'component_hash' => $component !== null
                ? ($this->dataObjects[$component]['x-laravel']['hash'] ?? null)
                : null,
        ];
    }

    public static function refFor(string $component): string
    {
        return '#/components/data_objects/'.$component;
    }

    protected function componentNameFromRef(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        $prefix = '#/components/data_objects/';

        return str_starts_with($ref, $prefix) ? substr($ref, strlen($prefix)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyForHashing(): array
    {
        return [
            'schema_format_version' => self::FORMAT,
            'application' => [
                // Environment, base URL and git state are per-machine and would make
                // two developers' documents differ for no contract reason.
                'api_prefix' => $this->application['api_prefix'] ?? null,
            ],
            'capabilities' => $this->capabilities,
            'auth' => $this->auth,
            'modules' => $this->modules,
            'endpoints' => array_map(
                static fn (array $endpoint): array => ['id' => $endpoint['id'], 'hash' => $endpoint['hash']],
                $this->endpoints
            ),
            'data_objects' => array_map(
                static fn (array $component): ?string => $component['x-laravel']['hash'] ?? null,
                $this->dataObjects
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_format_version' => self::FORMAT,
            'generated_at' => $this->generatedAt,
            'schema_hash' => $this->schemaHash,
            'application' => $this->application,
            'capabilities' => $this->capabilities,
            'auth' => $this->auth,
            'modules' => array_values($this->modules),
            'endpoints' => array_values($this->endpoints),
            'components' => [
                'data_objects' => $this->dataObjects ?: (object) [],
                'responses' => $this->responses ?: (object) [],
            ],
            'diagnostics' => $this->diagnostics->toArray(),
        ];
    }

    /**
     * Hashes only. Compiled from this same object so the manifest can never
     * disagree with the document it summarises.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $endpoints = [];
        foreach ($this->endpoints as $endpoint) {
            $endpoints[$endpoint['id']] = $endpoint['hash'];
        }
        ksort($endpoints);

        $dataObjects = [];
        foreach ($this->dataObjects as $name => $component) {
            $dataObjects[$name] = $component['x-laravel']['hash'] ?? null;
        }
        ksort($dataObjects);

        return [
            'schema_format_version' => self::FORMAT,
            'generated_at' => $this->generatedAt,
            'schema_hash' => $this->schemaHash,
            'application' => [
                'key' => $this->application['key'] ?? null,
                'environment' => $this->application['environment'] ?? null,
            ],
            'endpoints' => $endpoints ?: (object) [],
            'data_objects' => $dataObjects ?: (object) [],
            // The package compiles from the current codebase only; it has no memory
            // of previous documents, so it can never report removals. The Central App
            // diffs manifests to find those.
            'removed_since' => null,
        ];
    }

    public function schemaHash(): ?string
    {
        return $this->schemaHash;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function dataObjects(): array
    {
        return $this->dataObjects;
    }

    public function generatedAt(): string
    {
        return $this->generatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rehydrate a previously written document, for waypoint:check --baseline.
     *
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            $document['application'] ?? [],
            $document['capabilities'] ?? [],
            $document['auth'] ?? [],
            $document['modules'] ?? [],
            $document['endpoints'] ?? [],
            $document['components']['data_objects'] ?? [],
            $document['components']['responses'] ?? [],
            new Diagnostics,
            $document['generated_at'] ?? '',
            $document['schema_hash'] ?? null,
        );
    }
}
