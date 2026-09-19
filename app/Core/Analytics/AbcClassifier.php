<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Классификация A/B/C по методу Парето на кумулятивной доле выручки
 * товара за период (не по рангу/квартилям товаров, как было в
 * прежнем демо-seeder'е).
 *
 * ДОПУЩЕНИЕ (методология не была задана явно, зафиксировано в отчёте
 * stage-04): товары сортируются по убыванию выручки за весь период;
 * идёт накопление доли от общей выручки; первые товары, кумулятивно
 * дающие первые 80% выручки, — класс A; следующие до 95% — класс B;
 * остаток — класс C. Пороги 80/15/5 — классический вариант Парето для
 * ABC-анализа, не выведены из ТЗ или реальных данных клиента.
 *
 * Один снэпшот на товар за весь период (не по месяцам — классификация
 * не имеет смысла для отдельного месяца при таком методе), period —
 * последний месяц запрошенного периода, value — суммарная выручка
 * товара за период, value_meta — только abc_class (xyz_class
 * добавляется XyzClassifier'ом и объединяется на уровне сервиса,
 * вызывающего оба классификатора).
 */
final class AbcClassifier
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'abc_xyz_classification';

    private const float THRESHOLD_A = 0.8;

    private const float THRESHOLD_B = 0.95;

    /**
     * @param  iterable<Deal>  $deals
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, DateRange $period): array
    {
        $totalByProduct = [];
        foreach ($deals as $deal) {
            $totalByProduct[$deal->productId] = ($totalByProduct[$deal->productId] ?? 0.0) + $deal->amount;
        }

        if ($totalByProduct === []) {
            return [];
        }

        arsort($totalByProduct);
        $grandTotal = array_sum($totalByProduct);
        $lastMonth = $period->end->format('Y-m');

        $records = [];
        $cumulative = 0.0;
        foreach ($totalByProduct as $productId => $value) {
            $cumulative += $value;
            $cumulativeShare = $grandTotal > 0.0 ? $cumulative / $grandTotal : 1.0;

            $abcClass = match (true) {
                $cumulativeShare <= self::THRESHOLD_A => 'A',
                $cumulativeShare <= self::THRESHOLD_B => 'B',
                default => 'C',
            };

            $records[] = new MetricsSnapshotRecord(
                entityType: self::ENTITY_TYPE,
                entityId: $productId,
                metricKey: self::METRIC_KEY,
                value: $value,
                period: 'month:'.$lastMonth,
                valueMeta: ['abc_class' => $abcClass],
            );
        }

        return $records;
    }
}
