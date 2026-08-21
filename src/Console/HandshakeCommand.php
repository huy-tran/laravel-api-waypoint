<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Console;

use Composer\InstalledVersions;
use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use OutOfBoundsException;

/**
 * Hands a locally co-resident companion app everything it needs to connect.
 *
 * The Central App runs on the same machine as the project, so it never needed HTTP
 * to learn the secret: it can read it from the project directory. Being able to run
 * this command in this checkout is a stronger proof of "I am the local dev tool"
 * than anything available over the wire, where the only options are a shared secret
 * or trusting the network. So the secret stays mandatory on every route, and the
 * copy-paste it used to cost goes away instead.
 *
 * The payload also answers the question the HTTP surface deliberately cannot: a 404
 * cannot distinguish an unregistered surface from a wrong secret, and this reports
 * which of the three registration conditions is unmet.
 */
class HandshakeCommand extends Command
{
    protected $signature = 'waypoint:handshake
        {--json : Emit the payload as JSON and nothing else}';

    protected $description = 'Print the connection details a local companion app needs.';

    public function handle(): int
    {
        // Same refusal as waypoint:install, one step earlier: a secret belongs in a
        // developer's terminal, and this package has no business in production at
        // all.
        if ($this->laravel->environment('production')) {
            $this->components->error('waypoint:handshake does not run in production. This package is a development tool.');

            return self::FAILURE;
        }

        $payload = $this->payload();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->report($payload);
        }

        // Non-zero when the surface is not reachable, so a caller can branch on the
        // exit code alone and read the reason only when it needs to explain itself.
        return $payload['registered'] === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $prefix = trim((string) config('api-waypoint.prefix'), '/');
        $secret = (string) config('api-waypoint.secret');
        $reason = $this->unregisteredReason();

        return [
            'waypoint' => [
                'schema_format_version' => SchemaDocument::FORMAT,
                'package_version' => $this->packageVersion(),
            ],
            'application' => [
                'name' => (string) config('app.name'),
                'environment' => $this->laravel->environment(),
            ],
            // The route table is the ground truth, not the three conditions: a host
            // that never loaded the provider would still satisfy all three.
            'registered' => Route::has('api-waypoint.schema'),
            'unregistered_reason' => $reason,
            'connection' => [
                'base_url' => url($prefix),
                'header' => VerifyWaypointSecret::HEADER,
                // Null rather than an empty string, so a consumer cannot send it by
                // accident and read a 404 as a wrong secret.
                'secret' => $secret === '' ? null : $secret,
            ],
            // Published so the companion app never hardcodes a path. The document is
            // served at the prefix root, which is the one every consumer gets wrong.
            'paths' => [
                'schema' => '/'.$prefix,
                'manifest' => '/'.$prefix.'/manifest',
                'references' => '/'.$prefix.'/references/{table}/{column}',
                'scenarios' => '/'.$prefix.'/scenarios',
                'tokens' => '/'.$prefix.'/tokens',
            ],
        ];
    }

    /**
     * Which registration condition is unmet, in the order the provider checks them.
     */
    protected function unregisteredReason(): ?string
    {
        if (Route::has('api-waypoint.schema')) {
            return null;
        }

        if (config('api-waypoint.enabled') !== true) {
            return 'disabled';
        }

        $permitted = array_map('strval', (array) config('api-waypoint.environments', []));

        if (! in_array($this->laravel->environment(), $permitted, true)) {
            return 'environment_not_permitted';
        }

        if (! filled(config('api-waypoint.secret'))) {
            return 'no_secret';
        }

        return 'not_loaded';
    }

    protected function packageVersion(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion('hygo/laravel-api-waypoint');
        } catch (OutOfBoundsException) {
            // Running from a path repository or the package's own test suite, where
            // the package is not an installed dependency of itself.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function report(array $payload): void
    {
        $this->components->twoColumnDetail('Document', '<fg=cyan>'.$payload['connection']['base_url'].'</>');
        $this->components->twoColumnDetail('Header', $payload['connection']['header']);
        $this->components->twoColumnDetail(
            'Secret',
            $payload['connection']['secret'] === null
                ? '<fg=red>not set</>'
                : '<fg=green>'.$payload['connection']['secret'].'</>'
        );
        $this->components->twoColumnDetail('Wire format', $payload['waypoint']['schema_format_version']);
        $this->components->twoColumnDetail(
            'Surface',
            $payload['registered'] === true
                ? '<fg=green>registered</>'
                : '<fg=red>not registered ('.$payload['unregistered_reason'].')</>'
        );

        if ($payload['registered'] === true) {
            $this->newLine();
            $this->line('  Point the companion app at this project directory, or pass it');
            $this->line('  <fg=cyan>php artisan waypoint:handshake --json</> and let it read the payload.');

            return;
        }

        $this->newLine();
        $this->line('  '.$this->remedy((string) $payload['unregistered_reason']));
    }

    protected function remedy(string $reason): string
    {
        return match ($reason) {
            'disabled' => 'Set <fg=cyan>API_WAYPOINT_ENABLED=true</> in .env. Never in production.',
            'environment_not_permitted' => sprintf(
                'This application runs as "%s", which is not in api-waypoint.environments (%s).',
                $this->laravel->environment(),
                implode(', ', array_map('strval', (array) config('api-waypoint.environments', []))) ?: 'empty',
            ),
            'no_secret' => 'Set <fg=cyan>API_WAYPOINT_SECRET</> in .env, or run <fg=cyan>php artisan waypoint:install</>.',
            default => 'The routes are not in the route table. Check <fg=cyan>php artisan route:list --path='
                .trim((string) config('api-waypoint.prefix'), '/').'</>.',
        };
    }
}
