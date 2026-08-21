<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Install;

/**
 * Rewrites routes.include in a published config file.
 *
 * Only while it still holds the shipped default. Editing somebody's config with a
 * regular expression is worth doing exactly once, to replace a value they have
 * never touched; doing it to a value they chose would be a package overwriting a
 * decision, so that case reports back instead and leaves the file alone.
 */
class IncludePatch
{
    /** The default was replaced with the proposed patterns. */
    public const PATCHED = 'patched';

    /** The file already holds the proposed patterns. */
    public const ALREADY = 'already';

    /** Someone has changed this value; it was left alone. */
    public const CUSTOMISED = 'customised';

    /** The config file is not published. */
    public const UNPUBLISHED = 'unpublished';

    /** The key could not be located, or the write did not take. */
    public const FAILED = 'failed';

    /** The value the package ships, and the only one safe to overwrite. */
    private const SHIPPED_DEFAULT = ['api/*'];

    public function __construct(protected string $path) {}

    /**
     * The patterns currently configured, or null when they cannot be read.
     *
     * @return array<int, string>|null
     */
    public function current(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = (string) file_get_contents($this->path);

        if (preg_match("/'include'\s*=>\s*\[(?P<body>[^\]]*)\]/", $contents, $matches) !== 1) {
            return null;
        }

        preg_match_all("/'([^']*)'/", $matches['body'], $found);

        return $found[1];
    }

    /**
     * @param array<int, string> $patterns
     * @return string One of the class constants.
     */
    public function apply(array $patterns): string
    {
        if (! is_file($this->path)) {
            return self::UNPUBLISHED;
        }

        $current = $this->current();

        if ($current === null) {
            return self::FAILED;
        }

        if ($this->same($current, $patterns)) {
            return self::ALREADY;
        }

        if (! $this->same($current, self::SHIPPED_DEFAULT)) {
            return self::CUSTOMISED;
        }

        $contents = (string) file_get_contents($this->path);

        $patched = preg_replace_callback(
            "/'include'\s*=>\s*\[[^\]]*\]/",
            static fn (): string => "'include' => ".self::render($patterns),
            $contents,
            1
        );

        if (! is_string($patched) || $patched === $contents) {
            return self::FAILED;
        }

        file_put_contents($this->path, $patched, LOCK_EX);

        // Read back rather than trust the write: a half-applied edit to somebody's
        // config should be reported, not assumed.
        return $this->same($this->current() ?? [], $patterns) ? self::PATCHED : self::FAILED;
    }

    /**
     * @param array<int, string> $patterns
     */
    public static function render(array $patterns): string
    {
        return '['.implode(', ', array_map(
            static fn (string $pattern): string => "'".$pattern."'",
            $patterns
        )).']';
    }

    /**
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    protected function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
