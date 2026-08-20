<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Attributes;

use Attribute;

/**
 * Declares an endpoint's response shape on the Action class.
 *
 * The compiler never guesses a transformer, so this attribute (or the
 * ProvidesWaypointResponse contract) is the only way one appears in the document.
 *
 *   #[WaypointResponse(status: 201, transformer: OrderTransformer::class)]
 *   class CreateOrder { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class WaypointResponse
{
    /**
     * @param int|null $status HTTP status returned on success.
     * @param class-string|null $transformer Fractal transformer FQCN.
     * @param class-string|null $serializer Fractal serializer FQCN.
     * @param string|null $shape One of: opaque, collection, data_object, empty.
     * @param array<int, int> $errors Additional documented error statuses.
     */
    public function __construct(
        public ?int $status = null,
        public ?string $transformer = null,
        public ?string $serializer = null,
        public ?string $shape = null,
        public array $errors = [],
    ) {}
}
