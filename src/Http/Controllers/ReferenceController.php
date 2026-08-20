<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Support\ReferenceWhitelist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live reference data, so an exists: field gets a real value instead of a random
 * UUID that guarantees a 422.
 *
 * Every part of this endpoint is whitelisted rather than validated:
 *
 *  - the (table, column) pair must appear in the compiled schema, or in
 *    references.extra. A table that plainly exists in the database but is named
 *    nowhere in the schema is a 404.
 *  - the label column and every where[] key must be a real column on that table,
 *    checked with Schema::hasColumn(). Values are always bound.
 *  - configured redact columns can be neither read, labelled by, nor filtered on.
 *
 * The point is that this is a reference lookup, not a database browser behind one
 * shared secret.
 */
class ReferenceController
{
    private const MAX_LIMIT = 50;

    private const DEFAULT_LIMIT = 20;

    /** Never returned in context, however many rows would fit. */
    private const MAX_CONTEXT_COLUMNS = 6;

    public function __construct(protected ReferenceWhitelist $whitelist) {}

    public function __invoke(Request $request, string $table, string $column): JsonResponse
    {
        $entry = $this->whitelist->entry($table, $column);

        // Not whitelisted is indistinguishable from not existing, on purpose.
        if ($entry === null || $this->isRedacted($column)) {
            abort(404);
        }

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            abort(404);
        }

        $limit = max(1, min((int) $request->query('limit', (string) self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $label = $this->labelColumn($request, $table, $entry, $column);
        $constraint = $this->constraint($request, $table);

        $query = DB::table($table);

        foreach ($constraint as $key => $value) {
            // Bound, never interpolated. A value of "'; DROP TABLE orders; --" is
            // just a string that matches nothing.
            $query->where($key, '=', $value);
        }

        $total = (clone $query)->count();

        if (($fragment = $request->query('q')) !== null && $fragment !== '' && $label !== null) {
            $query->where($label, 'like', '%'.$this->escapeLike((string) $fragment).'%');
        }

        $matching = (clone $query)->count();

        $columns = $this->selectColumns($table, $column, $label);

        $rows = $query->orderBy($column)->limit($limit)->get($columns);

        $values = $rows->map(fn ($row): array => $this->describeRow((array) $row, $column, $label))->all();

        return response()->json(array_filter([
            'table' => $table,
            'column' => $column,
            'label_column' => $label,
            'constraint' => $constraint ?: null,
            'total_available' => $total,
            'returned' => count($values),
            'truncated' => $matching > count($values),
            'values' => $values,
            'hint' => $values === [] ? $this->hint($table) : null,
        ], static fn ($value, string $key): bool => $value !== null || in_array($key, ['label_column'], true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function labelColumn(Request $request, string $table, array $entry, string $column): ?string
    {
        $requested = $request->query('label');

        if (is_string($requested) && $requested !== '') {
            return $this->validColumn($table, $requested) ? $requested : null;
        }

        $configured = $entry['label'] ?? null;

        if (is_string($configured) && $this->validColumn($table, $configured)) {
            return $configured;
        }

        foreach (['name', 'title', 'label', 'reference', 'display_name', 'description'] as $candidate) {
            if ($this->validColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * where[col]=value, with every key checked against the real table schema.
     *
     * @return array<string, mixed>
     */
    protected function constraint(Request $request, string $table): array
    {
        $where = $request->query('where');

        if (! is_array($where)) {
            return [];
        }

        $constraint = [];

        foreach ($where as $key => $value) {
            if (! is_string($key) || is_array($value)) {
                abort(422, 'Constraint keys must be column names and values must be scalar.');
            }

            if (! $this->validColumn($table, $key)) {
                abort(422, sprintf('[%s] is not a filterable column on [%s].', $key, $table));
            }

            $constraint[$key] = $this->castConstraintValue($value);
        }

        return $constraint;
    }

    protected function castConstraintValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function selectColumns(string $table, string $column, ?string $label): array
    {
        $columns = [$column];

        if ($label !== null) {
            $columns[] = $label;
        }

        // A little context makes the picker usable: "Marguerite Okonkwo, active"
        // beats a bare UUID. Redacted columns never appear here.
        foreach (Schema::getColumnListing($table) as $candidate) {
            if (count($columns) >= self::MAX_CONTEXT_COLUMNS) {
                break;
            }

            if (in_array($candidate, $columns, true) || $this->isRedacted($candidate)) {
                continue;
            }

            $columns[] = $candidate;
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function describeRow(array $row, string $column, ?string $label): array
    {
        $context = [];

        foreach ($row as $key => $value) {
            if ($key === $column || $key === $label || $this->isRedacted((string) $key)) {
                continue;
            }

            $context[$key] = $value;
        }

        return array_filter([
            'value' => $row[$column] ?? null,
            'label' => $label !== null ? (string) ($row[$label] ?? '') : null,
            'context' => $context ?: null,
        ], static fn ($value, string $key): bool => $value !== null || $key === 'value', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, string>|null
     */
    protected function hint(string $table): ?array
    {
        $scenario = config('api-waypoint.references.scenario_hints.'.$table);

        return is_string($scenario) && $scenario !== ''
            ? ['message' => 'No matching records. Run a scenario first.', 'scenario' => $scenario]
            : ['message' => 'No matching records.'];
    }

    protected function validColumn(string $table, string $column): bool
    {
        if ($this->isRedacted($column)) {
            return false;
        }

        // Belt and braces: hasColumn() takes the name straight to the schema
        // inspector, so a non-identifier must not reach it.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            return false;
        }

        return Schema::hasColumn($table, $column);
    }

    protected function isRedacted(string $column): bool
    {
        $needle = strtolower($column);

        foreach ((array) config('api-waypoint.references.redact', []) as $denied) {
            if ($needle === strtolower((string) $denied)) {
                return true;
            }
        }

        return false;
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
