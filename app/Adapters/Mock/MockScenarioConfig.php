<?php

namespace App\Adapters\Mock;

/**
 * Сколько товаров MockAdapter отводит под каждый эталонный сценарий
 * (см. MockScenarioManifest). Сценарные товары занимают первые id
 * (prod-1, prod-2, ...) в порядке: dead, near_zero, gaps, spike,
 * seasonal, imbalance, lost_sales, no_sales_donor, returns; остальные —
 * обычные.
 */
final readonly class MockScenarioConfig
{
    public function __construct(
        public int $deadCount,
        public int $nearZeroCount,
        public int $gapCount,
        public int $spikeCount,
        public int $seasonalCount,
        /**
         * Возраст последней продажи «мёртвых» товаров в днях на historyEnd
         * (>= 90, порога неликвида по умолчанию): k-й мёртвый товар берёт
         * deadAges[k % count]. Возраст ограничивается глубиной истории
         * (см. MockAdapter::deadAgeFor).
         *
         * @var list<int>
         */
        public array $deadAges = [100, 250],
        /** Товаров с дисбалансом между двумя складами (сценарий 7, см. MockAdapter::imbalanceMovements). */
        public int $imbalanceCount = 0,
        /**
         * Товаров с «потерянными продажами»: обычная жизнь товара, но в
         * последнем месяце окна истории ни одной продажи со склада (и значит
         * сделки), остаток есть (сценарий для LostSalesCalculator, см.
         * MockAdapter::regularMovements).
         */
        public int $lostSalesCount = 0,
        /**
         * Товаров-«доноров без спроса»: остаток есть, продаж за всю
         * историю нет (сценарий для донора-по-остатку в
         * TransferRecommendationService, см. MockAdapter::noSalesDonorMovements).
         * У каждого — парный склад с дефицитом того же товара.
         */
        public int $noSalesDonorCount = 0,
        /**
         * Товаров с возвратами (отрицательные сделки): в последнем месяце окна
         * истории все продажи месяца возвращены (нетто 0, продажи есть), у
         * каждого второго — ещё и продажа предыдущего месяца (нетто < 0);
         * возвращённый товар приходит обратно на склад. См.
         * MockAdapter::returnsMovements.
         */
        public int $returnsCount = 0,
    ) {}
}
