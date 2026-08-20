<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Attributes;

use Attribute;

/**
 * Overrides the generation hint for one Data property.
 *
 * Sits just below config overrides in the FakerHintResolver precedence chain, so
 * it beats every inferred hint while a host app can still override it centrally.
 *
 *   #[WaypointFaker(strategy: 'person.lastName')]
 *   public string $surname;
 *
 *   #[WaypointFaker(strategy: 'pattern', pattern: 'ORD-######')]
 *   public string $reference;
 *
 *   #[WaypointFaker(strategy: 'reference', constraint: ['is_active' => true])]
 *   public int $productId;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class WaypointFaker
{
    /**
     * @param string|null $strategy A member of StrategyVocabulary::all().
     * @param string|null $pattern Symbol mask for "pattern" (# digit, ? letter).
     * @param string|null $format Date format for "date" / "date_range".
     * @param array<int, mixed>|null $values Explicit candidate values.
     * @param float|null $includeProbability 0..1 chance of including an optional property.
     * @param bool|null $unique Force uniqueness across generated payloads.
     * @param int|float|null $min Lower bound for numeric strategies.
     * @param int|float|null $max Upper bound for numeric strategies.
     * @param int|null $length Fixed length for "alphanumeric".
     * @param array<string, mixed>|null $constraint WHERE constraint for "reference".
     * @param string|null $reason Explanation, required when strategy is "unresolvable".
     */
    public function __construct(
        public ?string $strategy = null,
        public ?string $pattern = null,
        public ?string $format = null,
        public ?array $values = null,
        public ?float $includeProbability = null,
        public ?bool $unique = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?int $length = null,
        public ?array $constraint = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'strategy' => $this->strategy,
            'pattern' => $this->pattern,
            'format' => $this->format,
            'values' => $this->values,
            'include_probability' => $this->includeProbability,
            'unique' => $this->unique,
            'min' => $this->min,
            'max' => $this->max,
            'length' => $this->length,
            'constraint' => $this->constraint,
            'reason' => $this->reason,
        ], static fn ($value): bool => $value !== null);
    }
}
