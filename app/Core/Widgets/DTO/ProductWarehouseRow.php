<?php

namespace App\Core\Widgets\DTO;

/**
 * Склад в карточке товара за месяц. daysOfStock/dailyRate null — склад с
 * остатком без продаж (stock_no_demand).
 */
final readonly class ProductWarehouseRow
{
    public function __construct(
        public string $warehouseId,
        public string $warehouseName,
        public ?float $stockQty,
        public ?float $dailyRate,
        public ?float $daysOfStock,
    ) {}
}
