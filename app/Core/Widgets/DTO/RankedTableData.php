<?php

namespace App\Core\Widgets\DTO;

/**
 * Первые $rows строк выборки и полное число подходящих сущностей $total
 * («показано count($rows) из $total»). $period — ключ периода данных
 * (null, если снэпшотов метрики нет вовсе).
 *
 * @template T of object
 */
final readonly class RankedTableData
{
    /**
     * @param  list<T>  $rows
     */
    public function __construct(
        public ?string $period,
        public array $rows,
        public int $total,
    ) {}
}
