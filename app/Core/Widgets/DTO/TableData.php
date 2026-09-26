<?php

namespace App\Core\Widgets\DTO;

final readonly class TableData
{
    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     * @param  int|null  $total  сколько строк всего, если показаны не все («показано N из total»)
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public ?int $total = null,
    ) {}
}
