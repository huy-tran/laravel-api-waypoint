<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates a scenario's parameters against its own declared schema, runs it in a
 * transaction, and records what it created so the run can be undone.
 */
class ScenarioRunner
{
    public const TABLE = 'api_waypoint_scenario_runs';

    public function __construct(protected ScenarioRegistry $registry) {}

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function run(string $name, array $parameters, string $actor = 'anonymous'): array
    {
        $scenario = $this->registry->resolve($name);
        $class = $scenario::class;

        $validated = $this->validate($class::parameters(), $parameters);
        $cleanupToken = 'scn_'.Str::lower((string) Str::ulid());

        $records = DB::transaction(static fn (): array => $scenario->run($validated));

        $normalised = array_values(array_map(
            fn ($record): array => $this->normaliseRecord($record),
            array_filter($records, 'is_array')
        ));

        $this->record($cleanupToken, $name, $validated, $normalised, $actor);

        return [
            'created' => count($normalised),
            'records' => $normalised,
            'scenario' => $name,
            'cleanup_token' => $cleanupToken,
        ];
    }

    /**
     * Deletes in reverse creation order, so a child row never outlives the parent
     * it points at.
     *
     * Returns the number of records deleted, or null when the token is unknown.
     */
    public function cleanup(string $cleanupToken): ?int
    {
        $run = DB::table(self::TABLE)->where('cleanup_token', $cleanupToken)->first();

        if ($run === null) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $records */
        $records = json_decode((string) $run->records, true) ?: [];

        $deleted = 0;

        DB::transaction(function () use ($records, &$deleted): void {
            foreach (array_reverse($records) as $record) {
                $model = $record['model'] ?? null;
                $key = $record['key'] ?? null;

                if (! is_string($model) || $key === null || ! class_exists($model)) {
                    continue;
                }

                try {
                    /** @var Model $instance */
                    $instance = new $model;
                    $deleted += (int) $instance->newQuery()->whereKey($key)->delete();
                } catch (Throwable) {
                    // A record already removed by hand is not a failure worth aborting
                    // the whole cleanup for.
                    continue;
                }
            }
        });

        DB::table(self::TABLE)
            ->where('cleanup_token', $cleanupToken)
            ->update(['cleaned_up_at' => now()]);

        return $deleted;
    }

    /**
     * Translate the scenario's own JSON-Schema-ish parameter block into validation
     * rules. A scenario declares its parameters once and gets them checked without
     * writing a second, drifting copy as rules.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $schema, array $parameters): array
    {
        $properties = $schema['properties'] ?? [];

        if (! is_array($properties) || $properties === []) {
            return [];
        }

        $required = array_map('strval', (array) ($schema['required'] ?? []));
        $rules = [];
        $defaults = [];

        foreach ($properties as $key => $property) {
            if (! is_array($property)) {
                continue;
            }

            $rule = [in_array($key, $required, true) ? 'required' : 'sometimes'];

            $rule[] = match ($property['type'] ?? 'string') {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                default => 'string',
            };

            if (isset($property['enum']) && is_array($property['enum'])) {
                $rule[] = 'in:'.implode(',', array_map('strval', $property['enum']));
            }

            foreach (['minimum' => 'min', 'maximum' => 'max'] as $keyword => $laravel) {
                if (isset($property[$keyword]) && is_numeric($property[$keyword])) {
                    $rule[] = $laravel.':'.$property[$keyword];
                }
            }

            $rules[(string) $key] = $rule;

            if (array_key_exists('default', $property)) {
                $defaults[(string) $key] = $property['default'];
            }
        }

        // Unknown keys are dropped rather than rejected: a Central App built against
        // a newer schema should still be able to run an older scenario.
        $validated = Validator::make(
            array_intersect_key($parameters, $rules),
            $rules
        )->validate();

        return array_merge($defaults, $validated);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function normaliseRecord(array $record): array
    {
        return array_filter([
            'model' => isset($record['model']) ? (string) $record['model'] : null,
            'key' => $record['key'] ?? null,
            'route_key' => $record['route_key'] ?? null,
            'type' => isset($record['type']) ? (string) $record['type'] : null,
            'label' => isset($record['label']) ? (string) $record['label'] : null,
            'attributes' => isset($record['attributes']) && is_array($record['attributes']) ? $record['attributes'] : null,
            'related' => isset($record['related']) && is_array($record['related']) ? $record['related'] : null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<int, array<string, mixed>> $records
     */
    protected function record(string $token, string $name, array $parameters, array $records, string $actor): void
    {
        DB::table(self::TABLE)->insert([
            'cleanup_token' => $token,
            'scenario' => $name,
            'parameters' => json_encode($parameters),
            'records' => json_encode($records),
            'actor' => $actor,
            'created_at' => now(),
        ]);
    }
}
