<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Console;

use Hygo\ApiWaypoint\Install\EnvFile;
use Hygo\ApiWaypoint\Install\IncludePatch;
use Hygo\ApiWaypoint\Install\RoutePrefixDetector;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Console\Command;

/**
 * One command for the three things a new install gets wrong.
 *
 * routes.include has to match the host's real URI prefix or the document comes
 * out empty; the secret has to exist or no route registers at all; and both env
 * keys have to stay local-only. None of that is guesswork, so none of it should
 * be left to a README step that gets skipped.
 */
class InstallCommand extends Command
{
    protected $signature = 'waypoint:install
        {--force : Republish the config file over an existing one}
        {--secret= : Use this secret instead of generating one}
        {--include=* : Write these route patterns instead of the detected ones}
        {--skip-env : Leave .env and .env.example alone}';

    protected $description = 'Publish the config, detect the API route prefix and write the local env keys.';

    public function handle(RoutePrefixDetector $detector, SchemaRepository $schemas): int
    {
        // The package is dev-only, and the provider throws when it is enabled in
        // production. Writing that state into an env file there is the same mistake
        // one step earlier, so it does not get to happen.
        if ($this->laravel->environment('production')) {
            $this->components->error('waypoint:install does not run in production. This package is a development tool.');

            return self::FAILURE;
        }

        $this->publishConfig();

        $patterns = $this->resolvePatterns($detector);

        $this->writeIncludePatterns($patterns);

        if (! $this->option('skip-env')) {
            $this->writeEnv();
        }

        $this->report($patterns, $schemas);

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $path = config_path('api-waypoint.php');
        $force = (bool) $this->option('force');

        if (is_file($path) && ! $force) {
            $this->components->twoColumnDetail('config/api-waypoint.php', '<fg=gray>already published</>');

            return;
        }

        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'api-waypoint-config',
            '--force' => $force,
        ]));

        $this->components->twoColumnDetail(
            'config/api-waypoint.php',
            is_file($path) ? '<fg=green>published</>' : '<fg=red>could not publish</>'
        );
    }

    /**
     * @return array<int, string>
     */
    protected function resolvePatterns(RoutePrefixDetector $detector): array
    {
        /** @var array<int, string> $explicit */
        $explicit = (array) $this->option('include');

        if ($explicit !== []) {
            return array_values(array_filter($explicit));
        }

        $candidates = $detector->candidates();

        if ($candidates === []) {
            return [];
        }

        $this->newLine();
        $this->components->info('Detected API route prefixes:');
        $this->table(
            ['Pattern', 'Routes'],
            array_map(
                static fn (array $candidate): array => [$candidate['pattern'], (string) $candidate['routes']],
                $candidates
            )
        );

        return $detector->propose();
    }

    /**
     * @param array<int, string> $patterns
     */
    protected function writeIncludePatterns(array $patterns): void
    {
        if ($patterns === []) {
            $this->components->warn(
                'No route looked like an API surface, so routes.include was left alone. '
                .'Set it by hand to the prefix your endpoints are registered under.'
            );

            return;
        }

        $patch = new IncludePatch(config_path('api-waypoint.php'));
        $rendered = IncludePatch::render($patterns);

        match ($patch->apply($patterns)) {
            IncludePatch::PATCHED => $this->components->twoColumnDetail('routes.include', '<fg=green>set to '.$rendered.'</>'),
            IncludePatch::ALREADY => $this->components->twoColumnDetail('routes.include', '<fg=gray>already '.$rendered.'</>'),
            IncludePatch::CUSTOMISED => $this->reportCustomised($patch, $rendered),
            IncludePatch::UNPUBLISHED => $this->components->warn('config/api-waypoint.php is not published, so routes.include was not written.'),
            default => $this->components->warn('Could not write routes.include. Set it to '.$rendered.' by hand.'),
        };
    }

    protected function reportCustomised(IncludePatch $patch, string $rendered): void
    {
        $current = IncludePatch::render($patch->current() ?? []);

        $this->components->twoColumnDetail('routes.include', '<fg=yellow>left as '.$current.'</>');
        $this->components->warn(
            'routes.include has been customised, so it was not overwritten. '
            .'Detection suggests '.$rendered.'. Reconcile it yourself if that is wrong.'
        );
    }

    protected function writeEnv(): void
    {
        $env = new EnvFile($this->laravel->environmentFilePath());

        if (! $env->exists()) {
            $this->newLine();
            $this->components->warn('No .env file found. Add these two keys to your local environment:');
            $this->line('  <fg=cyan>API_WAYPOINT_ENABLED=true</>');
            $this->line('  <fg=cyan>API_WAYPOINT_SECRET='.$this->resolveSecret().'</>');

            return;
        }

        $comment = 'API Waypoint (dev-only - never enable outside local)';

        $this->reportEnv('API_WAYPOINT_ENABLED', $env->set('API_WAYPOINT_ENABLED', 'true', $comment));

        $secret = $this->resolveSecret();
        $result = $env->set('API_WAYPOINT_SECRET', $secret);

        $this->reportEnv('API_WAYPOINT_SECRET', $result);

        if ($result === EnvFile::ADDED || $result === EnvFile::FILLED) {
            $this->line('  <fg=gray>The Central App sends this as the X-Api-Waypoint-Secret header:</>');
            $this->line('  <fg=cyan>'.$secret.'</>');
        }

        $this->writeEnvExample();
    }

    /**
     * The example file documents both keys with the flag off and no secret, so a
     * teammate cloning the repository cannot inherit an enabled surface.
     *
     * Resolved beside the environment file rather than from base_path, so an
     * application that moved its .env keeps the pair together.
     */
    protected function writeEnvExample(): void
    {
        $example = new EnvFile(dirname($this->laravel->environmentFilePath()).DIRECTORY_SEPARATOR.'.env.example');

        if (! $example->exists()) {
            return;
        }

        $example->set('API_WAYPOINT_ENABLED', 'false', 'API Waypoint (dev-only - never enable outside local)');
        $example->set('API_WAYPOINT_SECRET', '');

        $this->components->twoColumnDetail('.env.example', '<fg=green>documented</>');
    }

    protected function reportEnv(string $key, string $result): void
    {
        $this->components->twoColumnDetail($key, match ($result) {
            EnvFile::ADDED => '<fg=green>added</>',
            EnvFile::FILLED => '<fg=green>filled in</>',
            EnvFile::KEPT => '<fg=gray>already set, left alone</>',
            default => '<fg=red>not written</>',
        });
    }

    protected function resolveSecret(): string
    {
        $supplied = $this->option('secret');

        if (is_string($supplied) && $supplied !== '') {
            return $supplied;
        }

        return bin2hex(random_bytes(32));
    }

    /**
     * @param array<int, string> $patterns
     */
    protected function report(array $patterns, SchemaRepository $schemas): void
    {
        $this->newLine();

        if ($patterns !== []) {
            // Compile against what was just written rather than what was loaded at
            // boot, so the count reported is the one these patterns produce.
            config()->set('api-waypoint.routes.include', $patterns);

            $counts = $schemas->fresh()->toArray()['diagnostics']['counts'];

            $this->components->info(sprintf(
                '%d routes collected, %d with an input schema, %d unmapped.',
                $counts['routes_total'] ?? 0,
                $counts['routes_mapped'] ?? 0,
                $counts['routes_unmapped'] ?? 0,
            ));
        }

        if ($this->laravel->configurationIsCached()) {
            $this->components->warn('Configuration is cached. Run config:clear before the new values take effect.');
        }

        $prefix = trim((string) config('api-waypoint.prefix'), '/');

        $this->line('  Next: <fg=cyan>php artisan waypoint:check</> to see what cannot be described yet.');
        $this->line('  Document: <fg=cyan>'.url($prefix).'</>');
        $this->line('  A 404 there means either the routes are not registered or the secret did not match.');
        $this->line('  <fg=gray>php artisan route:list --path='.$prefix.' tells those two apart.</>');
    }
}
