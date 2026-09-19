<?php

namespace App\Core\Domain\Enums;

/**
 * Что источник данных умеет отдавать сверх обязательного минимума
 * (товары, сделки). Потребитель метрики спрашивает
 * DataSourceAdapter::capabilities() и не вызывает то, чего нет.
 */
enum AdapterCapability: string
{
    /** История движений: fetchStockMovements(). */
    case StockMovements = 'stock_movements';

    /** Остатки на дату: fetchStock(). */
    case StockSnapshots = 'stock_snapshots';
}
