<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\StockMovementType;
use DateTimeImmutable;

final readonly class StockMovement
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $warehouseId,
        public float $quantity,
        public StockMovementType $type,
        public DateTimeImmutable $date,
        public array $meta = [],
    ) {
    }
}
