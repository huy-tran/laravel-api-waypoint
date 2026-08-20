<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles ?format=, so an older Central App talking to a newer package gets a
 * clear 409 rather than a document it will silently misread.
 */
final class FormatNegotiator
{
    /** @var array<int, string> */
    public const SUPPORTED = [SchemaDocument::FORMAT];

    public static function check(Request $request): ?JsonResponse
    {
        $requested = $request->query('format');

        if ($requested === null || $requested === '') {
            return null;
        }

        if (in_array((string) $requested, self::SUPPORTED, true)) {
            return null;
        }

        return response()->json([
            'message' => 'Unsupported schema format requested.',
            'code' => 'waypoint.format_unsupported',
            'requested' => (string) $requested,
            'supported' => self::SUPPORTED,
            'hint' => 'Update the Central App, or pin the package to a matching release.',
        ], 409)->withHeaders(['X-Api-Waypoint-Format' => SchemaDocument::FORMAT]);
    }
}
