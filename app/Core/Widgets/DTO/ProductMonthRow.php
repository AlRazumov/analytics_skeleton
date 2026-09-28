<?php

namespace App\Core\Widgets\DTO;

/**
 * Месяц в карточке товара. revenue — 0, если месяц рассчитан, а продаж
 * товара не было; unitsSold/closingStock/turnover — из метрики turnover
 * (null — её строки за месяц нет).
 */
final readonly class ProductMonthRow
{
    public function __construct(
        public string $period,
        public float $revenue,
        public ?float $unitsSold,
        public ?float $closingStock,
        public ?float $turnover,
    ) {}
}
