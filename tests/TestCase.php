<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Tests;

use Hygo\ApiWaypoint\ApiWaypointServiceProvider;
use Hygo\ApiWaypoint\Compiler\Data\EnumReader;
use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Lorisleiva\Actions\ActionServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Spatie\Fractal\FractalServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\QueryBuilder\QueryBuilderServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    public const SECRET = 'workbench-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // The enum reader memoises process-wide, which is right in production and
        // wrong across tests that define enums with the same name.
        EnumReader::flush();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Overriding this replaces Testbench's discovered list, so every provider a
        // real host application would have has to be named here. Leaving
        // spatie/laravel-data out is not harmless: its config never merges, its rule
        // inferrers never run, and the compiled document quietly loses the rules
        // Spatie would have inferred.
        return [
            LaravelDataServiceProvider::class,
            QueryBuilderServiceProvider::class,
            FractalServiceProvider::class,
            SanctumServiceProvider::class,
            // Ahead of the workbench, so its route registrar has rewritten
            // Action::class to Action@asController before the routes are declared,
            // which is the order a real application boots in.
            ActionServiceProvider::class,
            ApiWaypointServiceProvider::class,
            // The miniature host application. Without it there are no routes to
            // compile, and every integration test passes by describing nothing.
            WorkbenchServiceProvider::class,
        ];
    }

    /** @var array<string, mixed> Applied after the defaults, see withWaypointConfig(). */
    protected array $configOverrides = [];

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('api-waypoint.enabled', true);
            // Testbench runs as "testing", which is not a permitted environment by
            // default. Every HTTP test needs the surface registered.
            $config->set('api-waypoint.environments', ['local', 'testing']);
            $config->set('api-waypoint.secret', self::SECRET);
            $config->set('app.name', 'Acme Orders API');
            $config->set('app.url', 'http://acme-orders.test');
            $config->set('database.default', 'testing');

            foreach ($this->configOverrides as $key => $value) {
                $config->set($key, $value);
            }
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        // Only for tests that actually asked for a database. Registering migrations
        // for the rest makes Testbench try to migrate an app that has no schema.
        if (! method_exists($this, 'refreshTestDatabase')) {
            return;
        }

        // Sanctum ships its own migrations and Testbench does not run a package's
        // migrations for it, so the token tests would have no table to write to.
        if (class_exists(Sanctum::class)) {
            $this->loadMigrationsFrom(dirname((new ReflectionClass(Sanctum::class))->getFileName(), 2).'/database/migrations');
        }
    }

    /**
     * Rebuild the application with different configuration.
     *
     * Route registration is decided during boot, so testing that a route is absent
     * means booting a fresh application, not editing config afterwards.
     *
     * @param array<string, mixed> $overrides
     */
    public function withWaypointConfig(array $overrides): static
    {
        $this->configOverrides = array_merge($this->configOverrides, $overrides);

        $this->refreshApplication();

        // The in-memory database goes with the old application instance, so a test
        // using RefreshDatabase needs its schema back before it can carry on.
        if (method_exists($this, 'refreshTestDatabase')) {
            // The trait only migrates once per process unless this is reset, and the
            // fresh application has an empty database.
            RefreshDatabaseState::$migrated = false;

            $this->refreshTestDatabase();
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function secretHeader(?string $secret = null): array
    {
        return [VerifyWaypointSecret::HEADER => $secret ?? self::SECRET];
    }
}
