<?php

namespace App\Core\Widgets\DTO;

/**
 * Данные страницы рекомендаций перемещений. $period — ключ периода (null,
 * если метрики days_of_stock нет вовсе); $hasData — есть ли строки метрики за
 * период. $rows — первые строки (по лимиту), $total — всего рекомендаций.
 * $deficitPairs / $unmatchedDeficits — дефицитных пар и из них без донора.
 */
final readonly class TransferTableData
{
    /**
     * @param  list<TransferRow>  $rows
     */
    public function __construct(
        public ?string $period,
        public bool $hasData,
        public array $rows,
        public int $total,
        public int $deficitPairs,
        public int $unmatchedDeficits,
    ) {}
}
