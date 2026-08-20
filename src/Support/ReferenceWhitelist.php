<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Support;

/**
 * The set of (table, column) pairs the reference endpoint will read.
 *
 * Derived from the compiled schema: a pair is reachable only because some Data
 * property declares exists: or unique: against it, or because the app explicitly
 * listed it in references.extra. Everything else is a 404, including tables that
 * plainly exist in the database.
 *
 * This is the difference between "a dev tool that reads reference data" and "an
 * arbitrary database browser behind one shared secret".
 */
class ReferenceWhitelist
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $pairs = null;

    public function __construct(protected SchemaRepository $schemas) {}

    /**
     * @return array<string, mixed>|null The entry, or null when not whitelisted.
     */
    public function entry(string $table, string $column): ?array
    {
        return $this->pairs()[$this->key($table, $column)] ?? null;
    }

    public function allows(string $table, string $column): bool
    {
        return $this->entry($table, $column) !== null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }

        $pairs = [];

        foreach ($this->fromSchema() as $pair) {
            $pairs[$this->key($pair['table'], $pair['column'])] = $pair;
        }

        foreach ($this->fromConfig() as $pair) {
            // An explicit config entry wins, because it may add a label column the
            // schema could not know about.
            $pairs[$this->key($pair['table'], $pair['column'])] = $pair;
        }

        ksort($pairs);

        return $this->pairs = $pairs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fromSchema(): array
    {
        $found = [];

        $walk = function (array $node) use (&$walk, &$found): void {
            foreach (['exists', 'unique'] as $kind) {
                $declared = $node['x-laravel'][$kind] ?? null;

                if (is_array($declared) && isset($declared['table'], $declared['column'])) {
                    $found[] = [
                        'table' => (string) $declared['table'],
                        'column' => (string) $declared['column'],
                        'source' => $kind,
                    ];
                }
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $document = $this->schemas->document()->toArray();

        foreach ($document['components']['data_objects'] ?? [] as $component) {
            if (is_array($component)) {
                $walk($component);
            }
        }

        // A path parameter bound to a model is a legitimate lookup too: it is how
        // the Central App fills {order} with a real order.
        foreach ($document['endpoints'] ?? [] as $endpoint) {
            foreach ($endpoint['path_parameters'] ?? [] as $parameter) {
                $reference = $parameter['x-faker']['reference'] ?? null;

                if (is_array($reference) && isset($reference['table'], $reference['column'])) {
                    $found[] = [
                        'table' => (string) $reference['table'],
                        'column' => (string) $reference['column'],
                        'source' => 'route_binding',
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fromConfig(): array
    {
        $extra = [];

        foreach ((array) config('api-waypoint.references.extra', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['table'], $entry['column'])) {
                continue;
            }

            $extra[] = [
                'table' => (string) $entry['table'],
                'column' => (string) $entry['column'],
                'label' => isset($entry['label']) ? (string) $entry['label'] : null,
                'source' => 'config',
            ];
        }

        return $extra;
    }

    protected function key(string $table, string $column): string
    {
        return $table.'.'.$column;
    }

    /** Test seam: the pair set is memoised for the lifetime of one request. */
    public function flush(): void
    {
        $this->pairs = null;
    }
}
