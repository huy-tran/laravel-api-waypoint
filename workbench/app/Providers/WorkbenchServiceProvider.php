<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Orders\Models\Order;
use Modules\Orders\Waypoint\Scenarios\PaidOrder;
use Workbench\App\Waypoint\AdminWaypointUser;
use Workbench\App\Waypoint\CustomerWaypointUser;

/**
 * The miniature host application the package is compiled against.
 *
 * Two modules, six endpoints and one deliberately non-conforming action, so the
 * golden-file test covers every branch the pipeline has.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Repository::class)->set('api-waypoint.scenarios', [
            'paid_order' => PaidOrder::class,
        ]);

        $this->app->make(Repository::class)->set('api-waypoint.tokens.roles', [
            'admin' => ['abilities' => ['*'], 'resolver' => AdminWaypointUser::class],
            'customer' => ['abilities' => ['orders:read'], 'resolver' => CustomerWaypointUser::class],
        ]);

        Route::model('order', Order::class);

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
