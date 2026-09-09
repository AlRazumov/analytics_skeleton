<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\StockMovementType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * For type In/Out: warehouseId is the only relevant location, toWarehouseId
 * must be null. For type Transfer: warehouseId is the source (from),
 * toWarehouseId is the destination (to) and is required.
 */
final readonly class StockMovement
{
    public function __construct(
        public string $id,
        public string $productId,
        /** Source warehouse for Transfer; the only warehouse for In/Out. */
        public string $warehouseId,
        public float $quantity,
        public StockMovementType $type,
        public DateTimeImmutable $date,
        /** Destination warehouse; required for Transfer, must be null otherwise. */
        public ?string $toWarehouseId = null,
        public array $meta = [],
    ) {
        if ($this->type === StockMovementType::Transfer && $this->toWarehouseId === null) {
            throw new InvalidArgumentException('toWarehouseId is required when type is Transfer.');
        }

        if ($this->type !== StockMovementType::Transfer && $this->toWarehouseId !== null) {
            throw new InvalidArgumentException('toWarehouseId must be null unless type is Transfer.');
        }
    }
}
