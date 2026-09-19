<?php

namespace App\Core\Domain;

/**
 * Остаток товара на складе на конец запрошенного дня
 * (см. DataSourceAdapter::fetchStock()).
 */
final readonly class StockBalance
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public float $quantity,
    ) {}
}
