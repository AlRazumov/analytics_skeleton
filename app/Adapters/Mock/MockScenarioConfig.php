<?php

namespace App\Adapters\Mock;

/**
 * Сколько товаров MockAdapter отводит под каждый эталонный сценарий
 * (см. MockScenarioManifest). Сценарные товары занимают первые id
 * (prod-1, prod-2, ...) в порядке: dead, near_zero, gaps, spike,
 * seasonal; остальные — обычные.
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
    ) {}
}
