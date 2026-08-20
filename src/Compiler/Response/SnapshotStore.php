<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Response;

use Hygo\ApiWaypoint\Compiler\Support\CanonicalHasher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores sanitised examples of real responses.
 *
 * A snapshot is the honest alternative to deriving a body schema from a Fractal
 * transformer: rather than guess the shape, record one. Sanitisation is a
 * deny-list applied at every depth, and both strings and arrays are truncated, so
 * a snapshot stays small enough to live in the document.
 */
class SnapshotStore
{
    /** @var array<string, array<string, mixed>|null> */
    protected array $memo = [];

    public function __construct(
        protected string $disk = 'local',
        protected string $path = 'api-waypoint/snapshots',
        protected int $ttlDays = 30,
        /** @var array<int, string> */
        protected array $redact = [],
        protected int $maxStringLength = 500,
        protected int $maxArrayItems = 3,
    ) {}

    /**
     * @param array<string, mixed> $config The api-waypoint.snapshots config block.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['disk'] ?? 'local'),
            (string) ($config['path'] ?? 'api-waypoint/snapshots'),
            (int) ($config['ttl_days'] ?? 30),
            (array) ($config['redact'] ?? []),
            (int) ($config['max_string_length'] ?? 500),
            (int) ($config['max_array_items'] ?? 3),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $endpointId): ?array
    {
        if (array_key_exists($endpointId, $this->memo)) {
            return $this->memo[$endpointId];
        }

        try {
            $file = $this->file($endpointId);

            if (! $this->filesystem()->exists($file)) {
                return $this->memo[$endpointId] = null;
            }

            $decoded = json_decode((string) $this->filesystem()->get($file), true);

            return $this->memo[$endpointId] = is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return $this->memo[$endpointId] = null;
        }
    }

    public function isStale(string $endpointId): bool
    {
        $snapshot = $this->get($endpointId);

        if ($snapshot === null) {
            return true;
        }

        $capturedAt = strtotime((string) ($snapshot['captured_at'] ?? ''));

        if ($capturedAt === false) {
            return true;
        }

        return $capturedAt < strtotime("-{$this->ttlDays} days");
    }

    /**
     * @param mixed $body
     */
    public function put(string $endpointId, $body, string $capturedAt): void
    {
        $example = $this->sanitise($body);

        $this->filesystem()->put($this->file($endpointId), (string) json_encode([
            'endpoint_id' => $endpointId,
            'captured_at' => $capturedAt,
            'hash' => CanonicalHasher::hash($example),
            'example' => $example,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        unset($this->memo[$endpointId]);
    }

    /**
     * The document carries the snapshot without its endpoint id, which is already
     * the key it hangs from.
     *
     * @return array<string, mixed>|null
     */
    public function forDocument(string $endpointId): ?array
    {
        $snapshot = $this->get($endpointId);

        if ($snapshot === null) {
            return null;
        }

        return [
            'captured_at' => $snapshot['captured_at'] ?? null,
            'hash' => $snapshot['hash'] ?? null,
            'example' => $snapshot['example'] ?? null,
        ];
    }

    /**
     * @return array<int, array{endpoint_id: string, captured_at: string|null, age_days: int|null}>
     */
    public function list(): array
    {
        $found = [];

        try {
            $files = $this->filesystem()->files($this->path);
        } catch (Throwable) {
            return [];
        }

        foreach ($files as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }

            $decoded = json_decode((string) $this->filesystem()->get($file), true);
            $capturedAt = is_array($decoded) ? ($decoded['captured_at'] ?? null) : null;
            $timestamp = is_string($capturedAt) ? strtotime($capturedAt) : false;

            $found[] = [
                'endpoint_id' => is_array($decoded) ? (string) ($decoded['endpoint_id'] ?? basename($file, '.json')) : basename($file, '.json'),
                'captured_at' => is_string($capturedAt) ? $capturedAt : null,
                'age_days' => $timestamp === false ? null : (int) floor((time() - $timestamp) / 86400),
            ];
        }

        usort($found, static fn (array $a, array $b): int => $a['endpoint_id'] <=> $b['endpoint_id']);

        return $found;
    }

    public function prune(): int
    {
        try {
            $files = $this->filesystem()->files($this->path);
        } catch (Throwable) {
            return 0;
        }

        $deleted = 0;

        foreach ($files as $file) {
            if (str_ends_with($file, '.json') && $this->filesystem()->delete($file)) {
                $deleted++;
            }
        }

        $this->memo = [];

        return $deleted;
    }

    /**
     * Recursive deny-list sanitisation plus truncation.
     *
     * Keys are matched case-insensitively as substrings, so "authorization",
     * "card_number" and "customerTfn" are all caught by their configured stems.
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitise($value, int $depth = 0)
    {
        if ($depth > 12) {
            return '[truncated]';
        }

        if (is_string($value)) {
            return strlen($value) > $this->maxStringLength
                ? substr($value, 0, $this->maxStringLength).'...[truncated]'
                : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = array_slice($value, 0, $this->maxArrayItems);

            $sanitised = array_map(fn ($item) => $this->sanitise($item, $depth + 1), $items);

            if (count($value) > $this->maxArrayItems) {
                $sanitised[] = sprintf('...[%d more truncated]', count($value) - $this->maxArrayItems);
            }

            return $sanitised;
        }

        $sanitised = [];

        foreach ($value as $key => $item) {
            $sanitised[$key] = $this->shouldRedact((string) $key)
                ? '[redacted]'
                : $this->sanitise($item, $depth + 1);
        }

        return $sanitised;
    }

    public function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);

        foreach ($this->redact as $denied) {
            if (str_contains($needle, strtolower((string) $denied))) {
                return true;
            }
        }

        return false;
    }

    protected function file(string $endpointId): string
    {
        // Endpoint ids are dotted and safe, but a derived id can contain anything
        // the URI did, and this string becomes a path.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $endpointId) ?? 'unknown';

        return rtrim($this->path, '/').'/'.$safe.'.json';
    }

    protected function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
