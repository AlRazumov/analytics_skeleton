<?php

namespace App\Core\Widgets\DTO;

/**
 * Топ-N сущностей по метрике за период. $unassigned — строка «без
 * сущности» (например «Без продавца»), в рейтинг не входит. $coverage —
 * режим покрытия источника ('full'|'partial'), $coveragePercent — доля
 * продаж с известной сущностью; оба null для типов без такой строки.
 *
 * @phpstan-type Columns list<string>
 */
final readonly class TopNData
{
    /**
     * @param  list<TopNRow>  $rows
     * @param  list<string>  $columns  metric_key дополнительных колонок
     */
    public function __construct(
        public string $entityType,
        public string $metric,
        public string $period,
        public array $rows,
        public int $total,
        public array $columns = [],
        public ?TopNRow $unassigned = null,
        public ?string $coverage = null,
        public ?float $coveragePercent = null,
    ) {}
}
