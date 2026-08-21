<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint;

use Hygo\ApiWaypoint\Compiler\Faker\FakerHintResolver;
use Hygo\ApiWaypoint\Compiler\Input\InputResolver;
use Hygo\ApiWaypoint\Compiler\Input\Resolvers\ContractInputResolver;
use Hygo\ApiWaypoint\Compiler\Input\Resolvers\HandleParameterResolver;
use Hygo\ApiWaypoint\Compiler\Input\Resolvers\NullInputResolver;
use Hygo\ApiWaypoint\Compiler\Response\SnapshotStore;
use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Console\CheckCommand;
use Hygo\ApiWaypoint\Console\InstallCommand;
use Hygo\ApiWaypoint\Console\SchemaCommand;
use Hygo\ApiWaypoint\Console\SnapshotCommand;
use Hygo\ApiWaypoint\Exceptions\UnsafeConfigurationException;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointCheckTool;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointEndpointsTool;
use Hygo\ApiWaypoint\Mcp\Tools\WaypointEndpointTool;
use Hygo\ApiWaypoint\Support\ReferenceWhitelist;
use Hygo\ApiWaypoint\Support\ScenarioRegistry;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Tool;

class ApiWaypointServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-waypoint.php', 'api-waypoint');

        $this->registerCompiler();
    }

    public function boot(): void
    {
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            // Always registered: CI runs waypoint:check with the HTTP surface off.
            $this->commands([SchemaCommand::class, CheckCommand::class, SnapshotCommand::class, InstallCommand::class]);
        }

        // Before the registration gate below, deliberately: the tools read the
        // compiler, not the HTTP surface, and are useful in a checkout where the
        // surface is off.
        $this->registerMcpTools();

        $this->guardAgainstUnsafeConfiguration();

        if (! $this->waypointShouldRegister()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api-waypoint.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Offer the read-only tools to Laravel Boost's MCP server.
     *
     * Boost merges boost.mcp.tools.include into both the tool list it serves and the
     * allow-list its executor checks, so appending here is the whole integration.
     * Guarded on laravel/mcp because the tool classes extend its Tool: without it,
     * naming them at all would be a fatal error rather than a missing feature.
     */
    protected function registerMcpTools(): void
    {
        if (! class_exists(Tool::class)) {
            return;
        }

        config()->set('boost.mcp.tools.include', array_values(array_unique(array_merge(
            (array) config('boost.mcp.tools.include', []),
            [
                WaypointCheckTool::class,
                WaypointEndpointTool::class,
                WaypointEndpointsTool::class,
            ],
        ))));
    }

    /**
     * Registration is conditional, not protected.
     *
     * Failing any of these means the routes are absent from the route table
     * entirely, so a probe gets Laravel's own 404 and cannot tell this app from one
     * that never installed the package.
     */
    protected function waypointShouldRegister(): bool
    {
        return config('api-waypoint.enabled') === true
            && in_array($this->app->environment(), array_map('strval', (array) config('api-waypoint.environments', [])), true)
            && filled(config('api-waypoint.secret'));
    }

    /**
     * Fail loudly rather than quietly declining. A silent decline hides the mistake
     * until someone "fixes" it by making the config worse.
     */
    protected function guardAgainstUnsafeConfiguration(): void
    {
        $environments = array_map('strval', (array) config('api-waypoint.environments', []));

        if (in_array('production', $environments, true)) {
            throw UnsafeConfigurationException::productionEnvironmentListed();
        }

        if (config('api-waypoint.enabled') === true && $this->app->environment('production')) {
            throw UnsafeConfigurationException::enabledInProduction();
        }
    }

    /**
     * Only the collaborators that outlive a single compile are bound here. The
     * compiler builds its own pipeline per run, because the component registry and
     * the diagnostics bag carry state that must not leak between compiles.
     */
    protected function registerCompiler(): void
    {
        $this->app->singleton(ScenarioRegistry::class);

        $this->app->bind(FakerHintResolver::class, static fn (): FakerHintResolver => new FakerHintResolver(
            (array) config('api-waypoint.faker', [])
        ));

        $this->app->bind(SnapshotStore::class, static fn (): SnapshotStore => SnapshotStore::fromConfig(
            (array) config('api-waypoint.snapshots', [])
        ));

        // The chain is a container binding so a host app, or a future
        // InlineValidateResolver, can be appended without touching the compiler.
        $this->app->bind(InputResolver::class, static fn ($app): InputResolver => new InputResolver([
            $app->make(ContractInputResolver::class),
            $app->make(HandleParameterResolver::class),
            $app->make(NullInputResolver::class),
        ]));

        $this->app->bind(SchemaCompiler::class);

        // Scoped, not singleton: one compiled document per request, discarded after.
        $this->app->scoped(SchemaRepository::class);
        $this->app->scoped(ReferenceWhitelist::class);
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/api-waypoint.php' => config_path('api-waypoint.php'),
        ], 'api-waypoint-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'api-waypoint-migrations');
    }
}
