<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Support;

use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * Where the document comes from, and whether it is cached.
 *
 * In "local" the document is recompiled on every request. Correctness beats speed
 * there: a route walk plus reflection over a few hundred endpoints should land
 * well under the compile budget, and if it does not, that is a bug worth fixing
 * rather than caching around. Any other permitted environment caches, because
 * nobody is editing code on it between requests.
 */
class SchemaRepository
{
    public const CACHE_KEY = 'api-waypoint.document';

    /** Memoised for the lifetime of one request: GET / compiles once, not twice. */
    protected ?SchemaDocument $memo = null;

    public function __construct(protected SchemaCompiler $compiler) {}

    public function document(): SchemaDocument
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        if (! $this->shouldCache()) {
            return $this->memo = $this->compiler->compile();
        }

        $cached = $this->cache()->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->memo = SchemaDocument::fromArray($cached);
        }

        $document = $this->compiler->compile();

        $this->cache()->put(self::CACHE_KEY, $document->toArray(), (int) config('api-waypoint.cache.ttl', 300));

        return $this->memo = $document;
    }

    public function fresh(): SchemaDocument
    {
        $this->memo = null;

        return $this->memo = $this->compiler->compile();
    }

    public function clear(): void
    {
        $this->memo = null;
        $this->cache()->forget(self::CACHE_KEY);
    }

    protected function shouldCache(): bool
    {
        return ! app()->environment('local', 'testing');
    }

    protected function cache(): Cache
    {
        $store = config('api-waypoint.cache.store');

        return is_string($store) && $store !== ''
            ? CacheFacade::store($store)
            : CacheFacade::store();
    }
}
