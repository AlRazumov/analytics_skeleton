<?php

namespace App\Core\Widgets\DTO;

final readonly class MatrixCellData
{
    public function __construct(
        public string $rowKey,
        public string $colKey,
        public int $itemsCount,
        public float $value,
    ) {}
}
