<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Install\IncludePatch;

function publishedConfig(?string $include = null): string
{
    $directory = sys_get_temp_dir().'/api-waypoint-config';

    if (! is_dir($directory)) {
        mkdir($directory, 0o755, true);
    }

    $path = $directory.'/api-waypoint.php';
    $contents = (string) file_get_contents(__DIR__.'/../../config/api-waypoint.php');

    if ($include !== null) {
        $contents = (string) preg_replace("/'include' => \[[^\]]*\]/", "'include' => ".$include, $contents, 1);
    }

    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    $directory = sys_get_temp_dir().'/api-waypoint-config';

    if (is_dir($directory)) {
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('reads the patterns the shipped config holds', function (): void {
    expect((new IncludePatch(publishedConfig()))->current())->toBe(['api/*']);
});

it('reports an unpublished config rather than creating one', function (): void {
    $patch = new IncludePatch(sys_get_temp_dir().'/api-waypoint-config/absent.php');

    expect($patch->current())->toBeNull()
        ->and($patch->apply(['v1/*']))->toBe(IncludePatch::UNPUBLISHED);
});

it('replaces the shipped default', function (): void {
    $path = publishedConfig();
    $patch = new IncludePatch($path);

    expect($patch->apply(['v1/*']))->toBe(IncludePatch::PATCHED)
        ->and($patch->current())->toBe(['v1/*']);

    // Still valid PHP that still returns the whole config array.
    $config = require $path;

    expect($config['routes']['include'])->toBe(['v1/*'])
        ->and($config['routes']['exclude'])->toContain('sanctum/*')
        ->and($config)->toHaveKeys(['enabled', 'secret', 'prefix', 'tokens', 'scenarios']);
});

it('writes several patterns', function (): void {
    $path = publishedConfig();

    (new IncludePatch($path))->apply(['v1/*', 'v2/*']);

    expect((require $path)['routes']['include'])->toBe(['v1/*', 'v2/*']);
});

it('is idempotent', function (): void {
    $patch = new IncludePatch(publishedConfig());

    expect($patch->apply(['v1/*']))->toBe(IncludePatch::PATCHED)
        ->and($patch->apply(['v1/*']))->toBe(IncludePatch::ALREADY);
});

it('recognises the proposal already being in place, in any order', function (): void {
    $patch = new IncludePatch(publishedConfig("['v2/*', 'v1/*']"));

    expect($patch->apply(['v1/*', 'v2/*']))->toBe(IncludePatch::ALREADY);
});

it('refuses to overwrite a customised value', function (): void {
    $path = publishedConfig("['internal/*']");
    $patch = new IncludePatch($path);

    expect($patch->apply(['v1/*']))->toBe(IncludePatch::CUSTOMISED)
        ->and($patch->current())->toBe(['internal/*'])
        ->and((require $path)['routes']['include'])->toBe(['internal/*']);
});

it('renders patterns as the config literal they will become', function (): void {
    expect(IncludePatch::render(['v1/*']))->toBe("['v1/*']")
        ->and(IncludePatch::render(['v1/*', 'v2/*']))->toBe("['v1/*', 'v2/*']")
        ->and(IncludePatch::render([]))->toBe('[]');
});
