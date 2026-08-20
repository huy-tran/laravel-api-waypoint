<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Console;

use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Illuminate\Console\Command;

class SnapshotCommand extends Command
{
    protected $signature = 'waypoint:snapshot
        {--list : Show stored snapshots and their age}
        {--prune : Delete every stored snapshot}';

    protected $description = 'Inspect or clear recorded response snapshots.';

    public function handle(SnapshotStore $snapshots): int
    {
        if ($this->option('prune')) {
            $deleted = $snapshots->prune();

            $this->components->info(sprintf('Deleted %d snapshot(s).', $deleted));

            return self::SUCCESS;
        }

        $stored = $snapshots->list();

        if ($stored === []) {
            $this->components->info('No snapshots stored.');

            if (! (bool) config('api-waypoint.snapshots.enabled', false)) {
                $this->line('  Recording is off. Set API_WAYPOINT_SNAPSHOTS=true and add the '
                    .'RecordsWaypointResponses middleware to your API stack.');
            }

            return self::SUCCESS;
        }

        $ttl = (int) config('api-waypoint.snapshots.ttl_days', 30);

        $this->table(
            ['Endpoint', 'Captured at', 'Age (days)', 'Stale'],
            array_map(static fn (array $row): array => [
                $row['endpoint_id'],
                $row['captured_at'] ?? 'unknown',
                $row['age_days'] ?? '?',
                ($row['age_days'] ?? PHP_INT_MAX) > $ttl ? 'yes' : 'no',
            ], $stored)
        );

        return self::SUCCESS;
    }
}
