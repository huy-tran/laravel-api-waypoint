<?php

declare(strict_types=1);

namespace Workbench\App\Waypoint;

use Hygo\ApiWaypoint\Contracts\ResolvesWaypointUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Workbench\App\Models\User;

class AdminWaypointUser implements ResolvesWaypointUser
{
    public function resolve(string $email, string $role): Authenticatable
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Waypoint Admin', 'password' => bcrypt(str()->random(32))]
        );
    }
}
