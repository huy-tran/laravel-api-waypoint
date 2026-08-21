<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Install\IncludePatch;

/**
 * Redirect the config and environment paths into a scratch directory.
 *
 * The command writes to real files by design, so the test gives it real files to
 * write to rather than mocking the filesystem out of the thing under test.
 *
 * @return array{config: string, env: string, example: string}
 */
function installTarget(?string $include = null, string $env = "APP_NAME=Acme\n"): array
{
    $directory = sys_get_temp_dir().'/api-waypoint-install';

    if (! is_dir($directory.'/config')) {
        mkdir($directory.'/config', 0o755, true);
    }

    $config = (string) file_get_contents(__DIR__.'/../../config/api-waypoint.php');

    if ($include !== null) {
        $config = (string) preg_replace("/'include' => \[[^\]]*\]/", "'include' => ".$include, $config, 1);
    }

    file_put_contents($directory.'/config/api-waypoint.php', $config);
    file_put_contents($directory.'/.env', $env);
    file_put_contents($directory.'/.env.example', "APP_NAME=Acme\n");

    app()->useConfigPath($directory.'/config');
    app()->useEnvironmentPath($directory);

    return [
        'config' => $directory.'/config/api-waypoint.php',
        'env' => $directory.'/.env',
        'example' => $directory.'/.env.example',
    ];
}

afterEach(function (): void {
    $directory = sys_get_temp_dir().'/api-waypoint-install';

    if (! is_dir($directory)) {
        return;
    }

    array_map('unlink', glob($directory.'/config/*') ?: []);
    array_map('unlink', glob($directory.'/{.env,.env.example}', GLOB_BRACE) ?: []);

    if (is_dir($directory.'/config')) {
        rmdir($directory.'/config');
    }

    rmdir($directory);
});

it('is registered', function (): void {
    expect(array_keys(Artisan::all()))->toContain('waypoint:install');
});

it('writes the detected prefix into the published config', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install', ['--skip-env' => true])
        ->expectsOutputToContain('Detected API route prefixes')
        ->assertSuccessful();

    // The workbench serves api/v1, so api/* is what detection should land on, and
    // the shipped default already says so.
    expect((new IncludePatch($target['config']))->current())->toBe(['api/*']);
});

it('writes an explicitly requested pattern instead of detecting', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install', ['--include' => ['v1/*', 'v2/*'], '--skip-env' => true])
        ->assertSuccessful();

    expect((require $target['config'])['routes']['include'])->toBe(['v1/*', 'v2/*']);
});

it('leaves a customised include alone and says so', function (): void {
    $target = installTarget("['internal/*']");

    $this->artisan('waypoint:install', ['--include' => ['v9/*'], '--skip-env' => true])
        ->expectsOutputToContain('has been customised')
        ->assertSuccessful();

    expect((new IncludePatch($target['config']))->current())->toBe(['internal/*']);
});

it('adds both env keys and generates a secret', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install')->assertSuccessful();

    $env = (string) file_get_contents($target['env']);

    expect($env)->toContain('API_WAYPOINT_ENABLED=true')
        ->and($env)->toMatch('/API_WAYPOINT_SECRET=[0-9a-f]{64}/');
});

it('accepts a supplied secret', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install', ['--secret' => 'supplied-secret'])->assertSuccessful();

    expect((string) file_get_contents($target['env']))->toContain('API_WAYPOINT_SECRET=supplied-secret');
});

it('never rotates a secret that is already set', function (): void {
    $target = installTarget(env: "API_WAYPOINT_SECRET=established\n");

    $this->artisan('waypoint:install')
        ->expectsOutputToContain('already set')
        ->assertSuccessful();

    expect((string) file_get_contents($target['env']))->toContain('API_WAYPOINT_SECRET=established')
        ->and((string) file_get_contents($target['env']))->not->toContain('established=');
});

it('documents the keys in .env.example with the surface off', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install')->assertSuccessful();

    $example = (string) file_get_contents($target['example']);

    expect($example)->toContain('API_WAYPOINT_ENABLED=false')
        ->and($example)->toContain('API_WAYPOINT_SECRET=')
        // A cloned repository must not inherit a working secret.
        ->and($example)->not->toMatch('/API_WAYPOINT_SECRET=.+/');
});

it('leaves the environment alone when asked to', function (): void {
    $target = installTarget();

    $this->artisan('waypoint:install', ['--skip-env' => true])->assertSuccessful();

    expect((string) file_get_contents($target['env']))->not->toContain('API_WAYPOINT');
});

it('reports what the written patterns actually collect', function (): void {
    installTarget();

    $this->artisan('waypoint:install', ['--skip-env' => true])
        ->expectsOutputToContain('routes collected')
        ->assertSuccessful();
});

it('tells the reader how to disambiguate a 404', function (): void {
    installTarget();

    $this->artisan('waypoint:install', ['--skip-env' => true])
        ->expectsOutputToContain('route:list --path=')
        ->assertSuccessful();
});

it('refuses to run in production', function (): void {
    $target = installTarget();

    app()['env'] = 'production';

    $this->artisan('waypoint:install')
        ->expectsOutputToContain('does not run in production')
        ->assertFailed();

    // Nothing written: an enabled flag on a production box is the mistake the
    // provider's boot guard exists to catch, one step earlier.
    expect((string) file_get_contents($target['env']))->not->toContain('API_WAYPOINT');
});
