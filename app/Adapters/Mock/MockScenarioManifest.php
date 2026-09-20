<?php

namespace App\Adapters\Mock;

/**
 * Эталонные факты о сгенерированных MockAdapter данных: по ним будущие
 * тесты метрик сверяют результат, а не оценивают «на глаз». Часть
 * mock-слоя, в core не попадает. Даты — строки 'Y-m-d'.
 */
final readonly class MockScenarioManifest
{
    /**
     * @param  list<string>  $seasonalProductIds  годовая волна продаж: максимум в $seasonPeakMonth, минимум в $seasonTroughMonth
     * @param  array<string, string>  $deadProducts  productId => дата последней продажи (после неё до конца истории движений нет, остаток > 0)
     * @param  array<string, int>  $deadAges  productId => возраст последней продажи в днях на historyEnd (historyEnd − дата в deadProducts)
     * @param  array<string, array{warehouse_id: string, daily_rate: int, stock_at_end: int, days_to_zero: int}>  $nearZeroProducts  стабильные продажи daily_rate/день, остаток на конец истории = daily_rate × days_to_zero
     * @param  array<string, list<array{from: string, to: string}>>  $gapProducts  окна, где остаток весь день нулевой и продаж нет; до и после окна продажи есть
     * @param  array<string, array{from: string, to: string, multiplier: int, baseline_daily: int}>  $spikeProducts  на дни from..to продажи в multiplier раз выше baseline_daily
     * @param  bool  $hasTransfers  есть парные transfer_out/transfer_in (склада больше одного)
     */
    public function __construct(
        public string $historyStart,
        public string $historyEnd,
        public array $seasonalProductIds,
        public int $seasonPeakMonth,
        public int $seasonTroughMonth,
        public array $deadProducts,
        public array $deadAges,
        public array $nearZeroProducts,
        public array $gapProducts,
        public array $spikeProducts,
        public bool $hasTransfers,
    ) {}
}
