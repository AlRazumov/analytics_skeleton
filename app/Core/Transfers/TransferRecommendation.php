<?php

namespace App\Core\Transfers;

/**
 * Рекомендация: переместить $quantity штук товара со склада «откуда» на склад
 * «куда». Покрытие — в днях (остаток / скорость продаж). «Покрытие после» —
 * нарастающим итогом: после этой строки и всех предыдущих строк того же
 * получателя (для «куда») или того же донора (для «откуда»).
 */
final readonly class TransferRecommendation
{
    public function __construct(
        public string $productId,
        public string $fromWarehouseId,
        public string $toWarehouseId,
        public int $quantity,
        public float $fromCoverageBefore,
        public float $fromCoverageAfter,
        public float $toCoverageBefore,
        public float $toCoverageAfter,
        public float $toDailyRate,
    ) {}
}
