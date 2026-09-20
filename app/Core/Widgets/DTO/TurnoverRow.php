<?php

namespace App\Core\Widgets\DTO;

/** Строка таблицы оборачиваемости (штуки): продано и остаток на конец месяца из value_meta. */
final readonly class TurnoverRow
{
    public function __construct(
        public string $productId,
        public string $productName,
        public ?float $closingStock,
        public ?float $unitsSold,
        public float $turnover,
    ) {}
}
