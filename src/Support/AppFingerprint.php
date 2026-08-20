<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Str;
use Throwable;

/**
 * The "application" block: who is talking, and from what code.
 *
 * The git fields are what let the Central App say "this schema is from
 * feature/split-payments" rather than leaving a developer wondering why an
 * endpoint they just wrote is missing.
 */
final class AppFingerprint
{
    public const PACKAGE = 'hygo/laravel-api-waypoint';

    /**
     * @return array<string, mixed>
     */
    public static function describe(?string $apiPrefix): array
    {
        $name = (string) config('app.name', 'Laravel');

        return array_filter([
            'key' => Str::slug($name) ?: 'laravel',
            'name' => $name,
            'environment' => app()->environment(),
            'base_url' => rtrim((string) config('app.url', ''), '/') ?: null,
            'api_prefix' => $apiPrefix,
            'laravel_version' => app()->version(),
            'package_version' => self::packageVersion(),
            'git' => self::git(),
        ], static fn ($value): bool => $value !== null);
    }

    public static function packageVersion(): ?string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
                return InstalledVersions::getPrettyVersion(self::PACKAGE);
            }
        } catch (Throwable) {
            // Not installed through Composer, for instance a path repository in tests.
        }

        return null;
    }

    /**
     * Read from .git directly rather than shelling out: the compiler runs inside a
     * request, and spawning a process per schema request is not acceptable.
     *
     * @return array{branch: string|null, commit?: string}|null
     */
    public static function git(): ?array
    {
        $root = base_path('.git');

        if (! is_dir($root)) {
            return null;
        }

        $head = @file_get_contents($root.'/HEAD');

        if (! is_string($head)) {
            return null;
        }

        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            // Detached HEAD: the file holds the commit itself.
            return ['branch' => null, 'commit' => substr($head, 0, 7)];
        }

        $ref = substr($head, 5);
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;

        $commit = @file_get_contents($root.'/'.$ref);

        if (! is_string($commit)) {
            // A packed ref: look it up rather than reporting no commit at all.
            $commit = self::fromPackedRefs($root, $ref);
        }

        $git = ['branch' => $branch];

        if (is_string($commit) && trim($commit) !== '') {
            $git['commit'] = substr(trim($commit), 0, 7);
        }

        return $git;
    }

    private static function fromPackedRefs(string $root, string $ref): ?string
    {
        $packed = @file_get_contents($root.'/packed-refs');

        if (! is_string($packed)) {
            return null;
        }

        foreach (explode("\n", $packed) as $line) {
            $line = trim($line);

            if (! str_ends_with($line, ' '.$ref)) {
                continue;
            }

            // "<sha> refs/heads/main": the sha is everything up to the first space.
            return explode(' ', $line, 2)[0];
        }

        return null;
    }
}
