<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Hygo\ApiWaypoint\Support\ScenarioRegistry;
use Hygo\ApiWaypoint\Support\ScenarioRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Runs a declared scenario, by name.
 *
 * The request body carries a name and that scenario's own declared parameters,
 * and nothing else. There is deliberately no code path that accepts a class name,
 * a factory name or an attribute array: if it is not in
 * config('api-waypoint.scenarios'), the HTTP surface cannot reach it.
 */
class ScenarioController
{
    public function __construct(
        protected ScenarioRegistry $registry,
        protected ScenarioRunner $runner,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['scenarios' => $this->registry->describe()]);
    }

    public function store(Request $request): JsonResponse
    {
        $name = $request->input('scenario');

        if (! is_string($name) || ! $this->registry->has($name)) {
            return response()->json([
                'message' => sprintf('Unknown scenario `%s`.', is_string($name) ? $name : ''),
                'code' => 'waypoint.scenario_unknown',
                'available' => $this->registry->names(),
            ], 422);
        }

        $parameters = $request->input('parameters', []);

        if (! is_array($parameters)) {
            return response()->json([
                'message' => 'Scenario parameters must be an object.',
                'code' => 'waypoint.scenario_parameters_invalid',
            ], 422);
        }

        try {
            $result = $this->runner->run($name, $parameters, $this->actor($request));
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'The scenario parameters are invalid.',
                'code' => 'waypoint.scenario_parameters_invalid',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json($result, 201);
    }

    public function destroy(string $cleanupToken): JsonResponse
    {
        $deleted = $this->runner->cleanup($cleanupToken);

        if ($deleted === null) {
            return response()->json([
                'message' => sprintf('Unknown cleanup token `%s`.', $cleanupToken),
                'code' => 'waypoint.cleanup_token_unknown',
            ], 404);
        }

        return response()->json([
            'cleanup_token' => $cleanupToken,
            'deleted' => $deleted,
        ]);
    }

    protected function actor(Request $request): string
    {
        $presented = (string) ($request->header(VerifyWaypointSecret::HEADER) ?? '');

        return $presented === '' ? 'anonymous' : substr(hash('sha256', $presented), 0, 8);
    }
}
