<?php

namespace App\Core\Widgets\DTO;

/**
 * Диапазон значений метрики для подсчёта по корзинам. null у границы —
 * без ограничения с этой стороны.
 */
final readonly class ValueRange
{
    public function __construct(
        public ?float $min,
        public bool $minInclusive,
        public ?float $max,
        public bool $maxInclusive,
    ) {}

    /** Ровно одно значение. */
    public static function exactly(float $value): self
    {
        return new self($value, true, $value, true);
    }

    /** [min; max) — max = null: без верхней границы. */
    public static function halfOpen(float $min, ?float $max): self
    {
        return new self($min, true, $max, false);
    }

    /** (min; max) — min не входит, max не входит (null: без верхней границы). */
    public static function open(float $min, ?float $max): self
    {
        return new self($min, false, $max, false);
    }
}
