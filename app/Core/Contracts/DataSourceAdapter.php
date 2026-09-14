<?php

namespace App\Core\Contracts;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Product;
use App\Core\Domain\StockMovement;

/**
 * Потоковый контракт источника данных.
 *
 * Каждый метод вызывается один раз за прогон синхронизации (не для
 * каждого расчёта отдельно). Реализация не должна кешировать
 * состояние между вызовами — каждый вызов самодостаточен.
 */
interface DataSourceAdapter
{
    /**
     * @return iterable<Deal>
     */
    public function fetchDeals(DateRange $period): iterable;

    /**
     * @return iterable<Product>
     */
    public function fetchProducts(): iterable;

    /**
     * @return iterable<StockMovement>
     */
    public function fetchStockMovements(DateRange $period): iterable;
}
