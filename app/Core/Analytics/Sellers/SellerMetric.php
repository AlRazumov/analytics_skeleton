<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/**
 * Определение метрики продавца для реестра (config analytics.metrics.seller).
 * Метод requires() намеренно нет: наличие данных по продавцам проверяется
 * SellerCoverage адаптера на уровне всего блока.
 */
interface SellerMetric
{
    public function key(): string;

    public function label(): string;

    public function entityType(): string;

    /**
     * Значения за месячный период. Ключ — id продавца либо
     * SellerSalesData::NO_SELLER; сущность без значения пропускается.
     *
     * @return iterable<string, float>
     */
    public function compute(SellerSalesData $data, Period $period): iterable;
}
