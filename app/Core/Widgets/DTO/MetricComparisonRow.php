<?php

namespace App\Core\Widgets\DTO;

/**
 * Значение метрики сущности в текущем периоде и его сравнение с базой.
 * deltaAbs = value − baseValue; deltaPct = deltaAbs / |baseValue| × 100
 * (в процентах, как KpiCardData::$deltaPercent). Если базового снэпшота
 * нет — baseValue/deltaAbs/deltaPct = null; если база равна 0 —
 * deltaPct = null (делить не на что), deltaAbs считается.
 */
final readonly class MetricComparisonRow
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public float $value,
        public ?float $baseValue,
        public ?float $deltaAbs,
        public ?float $deltaPct,
    ) {}

    public static function of(string $entityType, string $entityId, float $value, ?float $baseValue): self
    {
        $deltaAbs = $baseValue === null ? null : $value - $baseValue;
        $deltaPct = $baseValue === null || $baseValue == 0.0 ? null : $deltaAbs / abs($baseValue) * 100;

        return new self($entityType, $entityId, $value, $baseValue, $deltaAbs, $deltaPct);
    }
}
