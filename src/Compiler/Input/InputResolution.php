<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Input;

/**
 * What an input resolver concluded.
 *
 * "No body" and "we could not find the body" are different answers and must not
 * collapse into one: the first is a complete description, the second is a gap the
 * CI lint should fail on.
 */
class InputResolution
{
    private function __construct(
        public readonly ?string $dataClass,
        public readonly ?string $reason,
        public readonly bool $declared,
        public readonly ?string $detail = null,
    ) {}

    public static function mapped(string $dataClass): self
    {
        return new self($dataClass, null, true);
    }

    /** The action declared, through the contract, that it takes no body. */
    public static function none(): self
    {
        return new self(null, null, true);
    }

    public static function unmapped(string $reason, ?string $detail = null): self
    {
        return new self(null, $reason, false, $detail);
    }

    public function isMapped(): bool
    {
        return $this->dataClass !== null;
    }
}
