<?php

namespace App\Core\Transfers;

/** Позиция товара на складе: остаток и средняя скорость продаж (штук в день). */
final readonly class TransferPosition
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public float $stock,
        public float $dailyRate,
    ) {}
}
