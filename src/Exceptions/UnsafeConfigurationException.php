<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when the package is configured in a way that would expose the
 * developer HTTP surface somewhere it must never exist.
 *
 * This deliberately fails loudly rather than silently declining to register: a
 * silent decline hides a config mistake that someone will later "fix" by adding
 * production to the allow list again.
 */
class UnsafeConfigurationException extends RuntimeException
{
    public static function productionEnvironmentListed(): self
    {
        return new self(
            'api-waypoint.environments must never contain "production". '
            .'This package exposes database reads, token minting and state seeding, and is '
            .'strictly a local development tool. Remove "production" from config/api-waypoint.php.'
        );
    }

    public static function enabledInProduction(): self
    {
        return new self(
            'api-waypoint.enabled is true while the application environment is "production". '
            .'Set API_WAYPOINT_ENABLED=false in the production environment immediately.'
        );
    }
}
