<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Install;

/**
 * Append-only editing of a .env file.
 *
 * The one rule worth stating: an existing non-empty value is never overwritten.
 * Re-running waypoint:install must not rotate a secret the Central App is already
 * configured with, and must not flip a flag somebody set deliberately.
 */
class EnvFile
{
    /** The key was not present and has been appended. */
    public const ADDED = 'added';

    /** The key was present but empty, and has been filled in place. */
    public const FILLED = 'filled';

    /** The key already had a value, which was left alone. */
    public const KEPT = 'kept';

    /** The file does not exist, so nothing was written. */
    public const MISSING = 'missing';

    public function __construct(protected string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The current value, or null when the key is absent. An empty declaration
     * (KEY=) reads as an empty string, which is a different fact from absence.
     */
    public function value(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $contents = (string) file_get_contents($this->path);

        if (preg_match('/^[ \t]*'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\"'\r");
    }

    /**
     * Ensure $key carries $value, without disturbing an existing one.
     *
     * Passing an empty $value means "ensure the key is declared", which is what
     * .env.example wants: the name documented, the value left for the developer.
     *
     * @return string One of the ADDED, FILLED, KEPT or MISSING constants.
     */
    public function set(string $key, string $value, ?string $comment = null): string
    {
        if (! $this->exists()) {
            return self::MISSING;
        }

        $contents = (string) file_get_contents($this->path);
        $current = $this->value($key);

        if ($current !== null && ($current !== '' || $value === '')) {
            return self::KEPT;
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        if ($current === '') {
            // A callback, not a replacement string: preg_replace would read a $ or a
            // backslash in the value as a backreference and quietly corrupt it.
            $patched = preg_replace_callback(
                '/^([ \t]*'.preg_quote($key, '/').'=).*$/m',
                static fn (array $matches): string => $matches[1].$value,
                $contents,
                1
            );

            file_put_contents($this->path, (string) $patched, LOCK_EX);

            return self::FILLED;
        }

        $block = $eol;

        if ($comment !== null) {
            $block .= '# '.$comment.$eol;
        }

        file_put_contents(
            $this->path,
            rtrim($contents, "\r\n").$eol.$block.$key.'='.$value.$eol,
            LOCK_EX
        );

        return self::ADDED;
    }
}
