<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SchemaController
{
    public function __construct(protected SchemaRepository $schemas) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        if (($mismatch = FormatNegotiator::check($request)) !== null) {
            return $mismatch;
        }

        $document = $this->schemas->document();
        $etag = '"'.$document->schemaHash().'"';

        // A matching If-None-Match means the Central App already has this exact
        // document, which on a large API is a couple of megabytes not sent.
        if ($this->matches($request, $etag)) {
            return response('', 304)->withHeaders($this->headers($etag));
        }

        return response()->json($document->toArray())->withHeaders($this->headers($etag));
    }

    protected function matches(Request $request, string $etag): bool
    {
        $presented = $request->header('If-None-Match');

        if ($presented === null) {
            return false;
        }

        foreach (explode(',', $presented) as $candidate) {
            if (trim(ltrim(trim($candidate), 'W/')) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(string $etag): array
    {
        return [
            'ETag' => $etag,
            // The document describes a codebase that changes under the developer's
            // hands; a stale cached copy is worse than a recompile.
            'Cache-Control' => 'no-store',
            'X-Api-Waypoint-Format' => SchemaDocument::FORMAT,
        ];
    }
}
