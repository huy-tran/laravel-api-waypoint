<?php

declare(strict_types=1);

namespace Modules\Reporting\Actions;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Export a report.
 *
 * Deliberately non-conforming: it validates inline, which v1 does not introspect.
 * It must appear in endpoints[] with "input": null and in unmapped_routes with
 * reason "no_data_class", never be silently dropped.
 */
class ExportReport
{
    use AsAction;

    /**
     * @return array<string, mixed>
     */
    public function asController(Request $request): array
    {
        $validated = $request->validate([
            'format' => ['required', 'in:csv,xlsx'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        return ['queued' => true] + $validated;
    }
}
