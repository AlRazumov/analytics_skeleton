<?php

namespace App\Core\Widgets\DTO;

/**
 * Строка таблицы категорий за месяц: выручка, доля в выручке всех категорий
 * (%), сравнение с базой (см. MetricComparisonRow) и число проданных товаров.
 */
final readonly class CategoryRow
{
    public function __construct(
        public string $categoryId,
        public string $categoryName,
        public float $value,
        public ?float $sharePct,
        public ?float $baseValue,
        public ?float $deltaAbs,
        public ?float $deltaPct,
        public ?int $productsSold,
    ) {}
}
