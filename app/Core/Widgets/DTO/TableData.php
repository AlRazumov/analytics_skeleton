<?php

namespace App\Core\Widgets\DTO;

final readonly class TableData
{
    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {}
}
