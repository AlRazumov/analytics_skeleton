<?php

namespace App\Core\Widgets\DTO;

/**
 * Строка таблицы «Неликвиды». Если продаж не было за весь просмотренный
 * период (no_sales_in_lookback), daysSinceLastSale — лишь нижняя граница
 * («не менее N дней»), lowerBound = true.
 */
final readonly class DeadStockRow
{
    public function __construct(
        public string $productId,
        public string $productName,
        public ?float $stockQty,
        public float $daysSinceLastSale,
        public bool $lowerBound,
    ) {}
}
