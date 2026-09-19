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
        /** Сколько последних дней истории у «мёртвого» товара нет движений (> 90). */
        public int $deadDays = 120,
    ) {}
}
