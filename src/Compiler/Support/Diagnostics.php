<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Support;

use InvalidArgumentException;

/**
 * Collects everything the compiler could not do, plus everything it did that a
 * human should look at.
 *
 * Nothing is ever silently dropped: an endpoint the compiler cannot describe is
 * still emitted with "input": null and is simultaneously listed here.
 */
class Diagnostics
{
    /** @var array<int, array<string, mixed>> */
    protected array $warnings = [];

    /** @var array<int, array<string, mixed>> */
    protected array $unmapped = [];

    /** @var array<string, int> */
    protected array $counts = [];

    /**
     * @param array<string, mixed> $context Recognised keys: endpoint_id, component, property.
     */
    public function warn(string $code, string $detail, array $context = []): void
    {
        if (! in_array($code, WarningCode::all(), true)) {
            throw new InvalidArgumentException("[{$code}] is not a member of the closed warning vocabulary.");
        }

        $warning = array_filter([
            'code' => $code,
            'endpoint_id' => $context['endpoint_id'] ?? null,
            'component' => $context['component'] ?? null,
            'property' => $context['property'] ?? null,
            'detail' => $detail,
        ], static fn ($value): bool => $value !== null);

        // Identical warnings are raised once per referencing endpoint; the Central
        // App only needs the fact, not the multiplicity.
        foreach ($this->warnings as $existing) {
            if ($existing === $warning) {
                return;
            }
        }

        $this->warnings[] = $warning;
    }

    /**
     * @param array<string, mixed> $context Recognised keys: route_name, action, detail.
     */
    public function unmapped(string $endpointId, string $method, string $uri, string $reason, array $context = []): void
    {
        if (! in_array($reason, UnmappedReason::all(), true)) {
            throw new InvalidArgumentException("[{$reason}] is not a member of the closed unmapped-reason vocabulary.");
        }

        $this->unmapped[] = array_filter([
            'endpoint_id' => $endpointId,
            'route_name' => $context['route_name'] ?? null,
            'method' => $method,
            'uri' => $uri,
            'action' => $context['action'] ?? null,
            'reason' => $reason,
            'detail' => $context['detail'] ?? UnmappedReason::remedy($reason),
        ], static fn ($value): bool => $value !== null);
    }

    public function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function set(string $key, int $value): void
    {
        $this->counts[$key] = $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unmappedRoutes(): array
    {
        return $this->unmapped;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function hasUnmapped(): bool
    {
        return $this->unmapped !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $warnings = $this->warnings;
        $unmapped = $this->unmapped;

        // Sorted so two compiles of an unchanged app produce byte-identical output
        // regardless of the order routes happened to come out of the router.
        usort($warnings, static fn (array $a, array $b): int => [$a['code'], $a['endpoint_id'] ?? '', $a['component'] ?? '', $a['property'] ?? '', $a['detail']]
            <=> [$b['code'], $b['endpoint_id'] ?? '', $b['component'] ?? '', $b['property'] ?? '', $b['detail']]);

        usort($unmapped, static fn (array $a, array $b): int => $a['endpoint_id'] <=> $b['endpoint_id']);

        $counts = $this->counts;
        ksort($counts);

        return [
            'unmapped_routes' => $unmapped,
            'warnings' => $warnings,
            'counts' => $counts + [
                'warnings' => count($warnings),
                'routes_unmapped' => count($unmapped),
            ],
        ];
    }
}
