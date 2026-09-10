<?php

namespace App\Core\Widgets\DTO;

final readonly class MatrixData
{
    /**
     * @param  string[]  $rowLabels
     * @param  string[]  $colLabels
     * @param  MatrixCellData[]  $cells
     */
    public function __construct(
        public array $rowLabels,
        public array $colLabels,
        public array $cells,
    ) {}
}
