<?php

namespace App\Core\Widgets\DTO;

use App\Core\Transfers\TransferDonorReason;

/**
 * Строка таблицы «Перемещения между складами»; покрытие — в днях.
 *
 * $donorReason::StockSurplus — ЭВРИСТИКА ДЛЯ ДЕМО (донор без продаж, см.
 * TransferDonorReason): fromCoverageBefore/fromCoverageAfter для такой
 * строки — INF (нет скорости продаж, которая бы их определяла).
 */
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
        public TransferDonorReason $donorReason = TransferDonorReason::Turnover,
    ) {}
}
