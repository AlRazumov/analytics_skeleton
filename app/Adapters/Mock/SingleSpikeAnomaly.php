<?php

namespace App\Adapters\Mock;

use DateTimeImmutable;

/**
 * Разовый всплеск спроса на один товар в один месяц (например,
 * маркетинговая акция или разовая крупная закупка). Множитель
 * ОРИЕНТИРОВОЧНЫЙ, не откалиброван под реальные данные.
 */
final class SingleSpikeAnomaly implements DemandAnomaly
{
    private const SPIKE_MULTIPLIER = 5.0;

    public function __construct(
        private readonly string $productId,
        private readonly int $year,
        private readonly int $month,
    ) {}

    public function multiplierFor(string $productId, DateTimeImmutable $date): ?float
    {
        if ($productId !== $this->productId) {
            return null;
        }

        if ((int) $date->format('Y') !== $this->year || (int) $date->format('n') !== $this->month) {
            return null;
        }

        return self::SPIKE_MULTIPLIER;
    }
}
