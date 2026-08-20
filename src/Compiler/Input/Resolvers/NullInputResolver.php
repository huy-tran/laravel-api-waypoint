<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input\Resolvers;

use Hygo\ApiWaypoint\Compiler\CollectedRoute;
use Hygo\ApiWaypoint\Compiler\Input\InputResolution;
use Hygo\ApiWaypoint\Compiler\Input\InputResolverContract;
use Hygo\ApiWaypoint\Compiler\ResolvedAction;
use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Illuminate\Http\UploadedFile;
use ReflectionNamedType;

/**
 * Resolver 3, terminal: always matches, and names the reason.
 *
 * The endpoint is still emitted with "input": null so the Central App can list it
 * as read-only. Being listed twice, once as an endpoint and once as a gap, is the
 * point: nothing is silently dropped.
 */
class NullInputResolver implements InputResolverContract
{
    public function resolve(CollectedRoute $route, ResolvedAction $action): ?InputResolution
    {
        if ($action->isClosure()) {
            return InputResolution::unmapped(
                UnmappedReason::CLOSURE_ACTION,
                'The route action is a closure, so there is nothing to reflect.'
            );
        }

        if (! $action->isReflectable()) {
            return InputResolution::unmapped(
                UnmappedReason::UNSUPPORTED_ACTION,
                sprintf('[%s] could not be reflected.', $action->class ?? 'unknown')
            );
        }

        if ($this->acceptsUploads($action)) {
            return InputResolution::unmapped(
                UnmappedReason::MULTIPART,
                'The action signature accepts an uploaded file. Multipart bodies are out of scope for waypoint v1.'
            );
        }

        // A GET or DELETE with no Data class is not a gap: there is no body to
        // describe. Reporting it as unmapped would make --fail-on-unmapped fire on
        // every index and show endpoint in the application, which is exactly the
        // kind of noise that gets a CI check switched off.
        if (! $route->carriesBody()) {
            return InputResolution::none();
        }

        return InputResolution::unmapped(
            UnmappedReason::NO_DATA_CLASS,
            sprintf(
                '[%s] takes no Data parameter and does not implement ProvidesWaypointInput. '
                .'Inline $request->validate() rules are not introspected.',
                $action->class ?? 'unknown'
            )
        );
    }

    protected function acceptsUploads(ResolvedAction $action): bool
    {
        if ($action->reflection === null) {
            return false;
        }

        foreach (['asController', 'handle', '__invoke'] as $name) {
            if (! $action->reflection->hasMethod($name)) {
                continue;
            }

            foreach ($action->reflection->getMethod($name)->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if (is_a($type->getName(), UploadedFile::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
