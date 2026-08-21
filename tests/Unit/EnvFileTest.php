<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Install\EnvFile;

function envPath(string $contents = ''): string
{
    $directory = sys_get_temp_dir().'/api-waypoint-env';

    if (! is_dir($directory)) {
        mkdir($directory, 0o755, true);
    }

    $path = $directory.'/.env';

    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    $directory = sys_get_temp_dir().'/api-waypoint-env';

    if (is_dir($directory)) {
        array_map('unlink', glob($directory.'/*') ?: []);
        array_map('unlink', glob($directory.'/.env') ?: []);
        rmdir($directory);
    }
});

it('reports a missing file rather than creating one', function (): void {
    $env = new EnvFile(sys_get_temp_dir().'/api-waypoint-env/absent-'.uniqid());

    expect($env->exists())->toBeFalse()
        ->and($env->set('API_WAYPOINT_ENABLED', 'true'))->toBe(EnvFile::MISSING);
});

it('appends an absent key with its comment', function (): void {
    $path = envPath("APP_NAME=Acme\n");

    $env = new EnvFile($path);

    expect($env->set('API_WAYPOINT_ENABLED', 'true', 'API Waypoint (dev-only)'))->toBe(EnvFile::ADDED);

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('# API Waypoint (dev-only)')
        ->and($contents)->toContain('API_WAYPOINT_ENABLED=true')
        ->and($contents)->toStartWith('APP_NAME=Acme');
});

it('fills a declared but empty key in place', function (): void {
    $path = envPath("API_WAYPOINT_SECRET=\nAPP_NAME=Acme\n");

    expect((new EnvFile($path))->set('API_WAYPOINT_SECRET', 'abc123'))->toBe(EnvFile::FILLED);

    // In place: the key keeps its position rather than being appended a second time.
    expect((string) file_get_contents($path))->toBe("API_WAYPOINT_SECRET=abc123\nAPP_NAME=Acme\n");
});

it('never overwrites a value that is already set', function (): void {
    $path = envPath("API_WAYPOINT_SECRET=existing\n");

    expect((new EnvFile($path))->set('API_WAYPOINT_SECRET', 'replacement'))->toBe(EnvFile::KEPT)
        ->and((string) file_get_contents($path))->toContain('API_WAYPOINT_SECRET=existing');
});

it('treats an empty value as ensure-declared, for .env.example', function (): void {
    $path = envPath("APP_NAME=Acme\n");
    $env = new EnvFile($path);

    expect($env->set('API_WAYPOINT_SECRET', ''))->toBe(EnvFile::ADDED)
        ->and((string) file_get_contents($path))->toContain('API_WAYPOINT_SECRET=');

    // And declaring it again leaves the blank alone.
    expect($env->set('API_WAYPOINT_SECRET', ''))->toBe(EnvFile::KEPT);
});

it('writes a value containing regex replacement characters verbatim', function (): void {
    // A preg_replace replacement string would read $1 as a backreference and a
    // backslash as an escape, and silently corrupt the secret.
    $path = envPath("API_WAYPOINT_SECRET=\n");
    $secret = 'a$1b\\c$0d';

    expect((new EnvFile($path))->set('API_WAYPOINT_SECRET', $secret))->toBe(EnvFile::FILLED)
        ->and((new EnvFile($path))->value('API_WAYPOINT_SECRET'))->toBe($secret);
});

it('preserves windows line endings when appending', function (): void {
    $path = envPath("APP_NAME=Acme\r\nAPP_ENV=local\r\n");

    (new EnvFile($path))->set('API_WAYPOINT_ENABLED', 'true', 'API Waypoint');

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain("# API Waypoint\r\n")
        ->and($contents)->toContain("API_WAYPOINT_ENABLED=true\r\n")
        ->and(substr_count($contents, "\n"))->toBe(substr_count($contents, "\r\n"));
});

it('distinguishes an absent key from a declared empty one', function (): void {
    $env = new EnvFile(envPath("DECLARED=\n"));

    expect($env->value('DECLARED'))->toBe('')
        ->and($env->value('ABSENT'))->toBeNull();
});

it('reads a quoted value without its quotes', function (): void {
    $env = new EnvFile(envPath("API_WAYPOINT_SECRET=\"quoted\"\n"));

    expect($env->value('API_WAYPOINT_SECRET'))->toBe('quoted');
});
