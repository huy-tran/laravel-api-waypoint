<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler;

use ReflectionClass;

/**
 * What the compiler knows about the class behind a route.
 */
class ResolvedAction
{
    public const TYPE_LARAVEL_ACTIONS = 'laravel-actions';

    public const TYPE_CONTROLLER = 'controller';

    public const TYPE_CLOSURE = 'closure';

    /**
     * @param class-string|null $class
     * @param ReflectionClass<object>|null $reflection
     */
    public function __construct(
        public readonly ?string $class,
        public readonly ?string $method,
        public readonly string $type,
        public readonly bool $asController,
        public readonly ?ReflectionClass $reflection = null,
    ) {}

    public function isClosure(): bool
    {
        return $this->type === self::TYPE_CLOSURE;
    }

    public function isReflectable(): bool
    {
        return $this->reflection !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'as_controller' => $this->asController,
        ];
    }
}
