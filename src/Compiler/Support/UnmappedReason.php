<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Support;

/**
 * The closed set of reasons an endpoint has no input schema.
 *
 * Every unmapped route carries exactly one of these, and each has a documented
 * remedy in the README. "We could not work it out" is not a permitted reason.
 */
final class UnmappedReason
{
    /** Action has no Data parameter and no waypointInput() contract method. */
    public const NO_DATA_CLASS = 'no_data_class';

    /** Route accepts file uploads. v1 does not describe multipart bodies. */
    public const MULTIPART = 'multipart';

    /** Route action is a closure, so there is nothing to reflect. */
    public const CLOSURE_ACTION = 'closure_action';

    /** Action class exists but could not be reflected. */
    public const UNSUPPORTED_ACTION = 'unsupported_action';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::NO_DATA_CLASS,
            self::MULTIPART,
            self::CLOSURE_ACTION,
            self::UNSUPPORTED_ACTION,
        ];
    }

    public static function remedy(string $reason): string
    {
        return match ($reason) {
            self::NO_DATA_CLASS => 'Type-hint a Spatie Data class on the Action handle()/asController() signature, '
                .'or implement ProvidesWaypointInput::waypointInput().',
            self::MULTIPART => 'File uploads are out of scope for waypoint v1. Split the upload into its own endpoint '
                .'or exclude this route in config("api-waypoint.routes.exclude").',
            self::CLOSURE_ACTION => 'Move the closure into an Action or controller class.',
            self::UNSUPPORTED_ACTION => 'The action class could not be reflected. Check it is autoloadable and not abstract.',
            default => 'Unknown reason.',
        };
    }
}
