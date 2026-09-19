<?php

namespace App\Core\Widgets\DTO;

/** Строка таблицы топ/анти-топ товаров со сравнением с предыдущим периодом. */
final readonly class TopProductRow
{
    public function __construct(
        public string $productId,
        public string $productName,
        public float $value,
        public ?float $baseValue,
        public ?float $deltaAbs,
        public ?float $deltaPct,
    ) {}
}
