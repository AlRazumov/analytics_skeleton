<?php

namespace App\Core\Transfers;

/**
 * ЭВРИСТИКА ДЛЯ ДЕМО (см. TransferDonorReason::StockSurplus): склад-донор
 * без спроса, уже прошедший отбор по порогу (analytics.transfers.
 * stock_surplus_min_stock) на стороне вызывающего кода — $available это
 * уже готовое к раздаче количество (остаток минус порог), а не сырой
 * остаток. TransferPlanner эту величину не пересчитывает.
 */
final readonly class StockSurplusDonor
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public float $available,
    ) {}
}
