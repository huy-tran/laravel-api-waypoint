<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-endpoint and per-Data-object hashes only: a few KB, so the Central App can
 * show "14 endpoints changed" without pulling the whole document.
 *
 * Compiled from the same SchemaDocument the full response uses, never from a
 * separate cheaper path, because two paths eventually disagree.
 */
class ManifestController
{
    public function __construct(protected SchemaRepository $schemas) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (($mismatch = FormatNegotiator::check($request)) !== null) {
            return $mismatch;
        }

        $document = $this->schemas->document();

        return response()->json($document->manifest())->withHeaders([
            'ETag' => '"'.$document->schemaHash().'"',
            'Cache-Control' => 'no-store',
            'X-Api-Waypoint-Format' => SchemaDocument::FORMAT,
        ]);
    }
}
