<?php

namespace App\Core\Transfers;

/**
 * Почему склад признан донором в TransferRecommendation.
 *
 * Turnover — обычный донор: посчитан по days_of_stock (остаток / скорость
 * продаж), покрытие >= surplus_days.
 *
 * StockSurplus — ЭВРИСТИКА ДЛЯ ДЕМО (не финальное продуктовое решение,
 * см. Known issues в docs/roadmap.md, «склад без продаж не считается
 * донором»): склад без единой продажи (для него не считается
 * days_of_stock — метрика не пишется без спроса), но с остатком выше
 * порога analytics.transfers.stock_surplus_min_stock. У такого донора
 * нет скорости продаж, поэтому его покрытие не определено (условно
 * бесконечно — см. TransferPlanner).
 */
enum TransferDonorReason: string
{
    case Turnover = 'turnover';
    case StockSurplus = 'stock_surplus';
}
