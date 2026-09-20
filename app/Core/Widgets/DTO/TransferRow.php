<?php

namespace App\Core\Widgets\DTO;

/** Строка таблицы «Перемещения между складами»; покрытие — в днях. */
final readonly class TransferRow
{
    public function __construct(
        public string $productId,
        public string $productName,
        public string $fromWarehouseId,
        public string $fromWarehouseName,
        public string $toWarehouseId,
        public string $toWarehouseName,
        public int $quantity,
        public float $fromCoverageBefore,
        public float $fromCoverageAfter,
        public float $toCoverageBefore,
        public float $toCoverageAfter,
        public float $toDailyRate,
    ) {}
}
