<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Console;

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Console\Command;

/**
 * The CI lint.
 *
 * Works with the package "disabled", because CI should never need the HTTP
 * surface switched on: only route registration is gated on enabled, never the
 * compiler.
 */
class CheckCommand extends Command
{
    protected $signature = 'waypoint:check
        {--fail-on-unmapped : Exit 1 when any route has no input schema}
        {--fail-on-warning : Exit 1 on any diagnostic warning}
        {--baseline= : Compare against a committed document and exit 1 on any hash change}';

    protected $description = 'Compile the waypoint schema and report gaps, warnings and drift.';

    public function handle(SchemaRepository $schemas): int
    {
        $document = $schemas->fresh();
        $diagnostics = $document->toArray()['diagnostics'];
        $counts = $diagnostics['counts'];

        $this->components->info(sprintf(
            '%d routes, %d mapped, %d unmapped, %d data objects, %d warnings. Compiled in %dms.',
            $counts['routes_total'] ?? 0,
            $counts['routes_mapped'] ?? 0,
            $counts['routes_unmapped'] ?? 0,
            $counts['data_objects'] ?? 0,
            $counts['warnings'] ?? 0,
            $counts['compile_ms'] ?? 0,
        ));

        // Each reported category contributes whether it should block, so the exit
        // code is a fold over them rather than a flag reassigned in four places.
        $blockers = [];

        if ($diagnostics['unmapped_routes'] !== []) {
            $this->reportUnmapped($diagnostics['unmapped_routes']);

            $blockers[] = (bool) $this->option('fail-on-unmapped');
        }

        if ($diagnostics['warnings'] !== []) {
            $this->reportWarnings($diagnostics['warnings']);

            $blockers[] = (bool) $this->option('fail-on-warning');
        }

        $baseline = $this->option('baseline');

        if (is_string($baseline) && $baseline !== '') {
            $blockers[] = $this->reportDrift($baseline, $document);
        }

        $failed = in_array(true, $blockers, true);

        if (! $failed) {
            $this->components->info('No blocking issues.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $unmapped
     */
    protected function reportUnmapped(array $unmapped): void
    {
        $this->newLine();
        $this->components->warn(sprintf('%d route(s) have no input schema:', count($unmapped)));

        $this->table(
            ['Endpoint', 'Method', 'URI', 'Reason'],
            array_map(static fn (array $route): array => [
                $route['endpoint_id'],
                $route['method'],
                $route['uri'],
                $route['reason'],
            ], $unmapped)
        );

        foreach ($this->groupBy($unmapped, 'reason') as $reason => $routes) {
            $this->line(sprintf('  <fg=yellow>%s</> (%d): %s', $reason, count($routes), $routes[0]['detail'] ?? ''));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     */
    protected function reportWarnings(array $warnings): void
    {
        $this->newLine();
        $this->components->warn(sprintf('%d warning(s):', count($warnings)));

        foreach ($this->groupBy($warnings, 'code') as $code => $group) {
            $this->line(sprintf('  <fg=yellow>%s</> (%d)', $code, count($group)));

            foreach (array_slice($group, 0, 5) as $warning) {
                $subject = $warning['endpoint_id'] ?? $warning['component'] ?? '';
                $property = isset($warning['property']) ? '.'.$warning['property'] : '';

                $this->line(sprintf('    %s%s  %s', $subject, $property, $warning['detail']));
            }

            if (count($group) > 5) {
                $this->line(sprintf('    ... and %d more', count($group) - 5));
            }
        }
    }

    /**
     * The "collection cannot go stale" enforcement: a PR that changes an endpoint
     * must regenerate the committed baseline.
     */
    protected function reportDrift(string $path, SchemaDocument $document): bool
    {
        if (! is_file($path)) {
            $this->components->error("Baseline [{$path}] does not exist. Generate one with waypoint:schema --output.");

            return true;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->components->error("Baseline [{$path}] is not valid JSON.");

            return true;
        }

        $before = SchemaDocument::fromArray($decoded)->manifest();
        $after = $document->manifest();

        $changes = array_merge(
            $this->diffHashes((array) $before['endpoints'], (array) $after['endpoints'], 'endpoint'),
            $this->diffHashes((array) $before['data_objects'], (array) $after['data_objects'], 'data object'),
        );

        if ($changes === []) {
            $this->components->info('Baseline matches.');

            return false;
        }

        $this->newLine();
        $this->components->error(sprintf('%d change(s) since the baseline:', count($changes)));
        $this->table(['Kind', 'Name', 'Change', 'Before', 'After'], $changes);
        $this->line('  Regenerate with: <fg=cyan>php artisan waypoint:schema --pretty --output='.$path.'</>');

        return true;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, array<int, string>>
     */
    protected function diffHashes(array $before, array $after, string $kind): array
    {
        $changes = [];

        foreach ($before as $name => $hash) {
            if (! array_key_exists($name, $after)) {
                $changes[] = [$kind, (string) $name, 'removed', (string) $hash, '-'];

                continue;
            }

            if ($after[$name] !== $hash) {
                $changes[] = [$kind, (string) $name, 'changed', (string) $hash, (string) $after[$name]];
            }
        }

        foreach ($after as $name => $hash) {
            if (! array_key_exists($name, $before)) {
                $changes[] = [$kind, (string) $name, 'added', '-', (string) $hash];
            }
        }

        return $changes;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function groupBy(array $rows, string $key): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row[$key]][] = $row;
        }

        ksort($grouped);

        return $grouped;
    }
}
