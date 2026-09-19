<?php

namespace App\Core\Widgets\DTO;

/** Строка таблицы «Риск дефицита» (пара товар × склад). */
final readonly class StockoutRiskRow
{
    public function __construct(
        public string $productId,
        public string $productName,
        public string $warehouseId,
        public string $warehouseName,
        public ?float $stockQty,
        public ?float $dailyRate,
        public float $daysOfStock,
    ) {}
}
