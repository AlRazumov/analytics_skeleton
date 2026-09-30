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
 * идёт накопление доли от общей выручки. Класс определяет доля,
 * накопленная ДО товара: меньше 80% — A, меньше 95% — B, иначе C. Так
 * товар, пересекающий границу, остаётся в более важном классе, а товар с
 * долей выше 80% (95%) не «выпадает» в B (C) — первый товар всегда A.
 * Пороги 80/15/5 — классический вариант Парето для ABC-анализа, не
 * выведены из ТЗ или реальных данных клиента. (До этапа-правки
 * 2026-09-30 класс определяла доля ВКЛЮЧАЯ товар — доминирующий товар
 * получал B или C.)
 *
 * Выручка — нетто (возвраты — отрицательные сделки). В накопление и в
 * общую сумму входят только товары с нетто > 0, поэтому доля не
 * превышает 1 и не зависит от возвратов по другим товарам; товары с
 * нетто ≤ 0 (продажи погашены возвратами) — сразу класс C.
 *
 * Один снэпшот на товар за весь период (не по месяцам — классификация
 * не имеет смысла для отдельного месяца при таком методе), period —
 * последний месяц запрошенного периода, value — суммарная выручка
 * товара за период (нетто, может быть ≤ 0), value_meta — только abc_class (xyz_class
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
        $grandTotal = array_sum(array_filter($totalByProduct, static fn (float $value): bool => $value > 0.0));
        $lastMonth = $period->end->format('Y-m');

        $records = [];
        $cumulative = 0.0;
        foreach ($totalByProduct as $productId => $value) {
            if ($value > 0.0) {
                $shareBefore = $cumulative / $grandTotal;
                $cumulative += $value;
                $abcClass = match (true) {
                    $shareBefore < self::THRESHOLD_A => 'A',
                    $shareBefore < self::THRESHOLD_B => 'B',
                    default => 'C',
                };
            } else {
                $abcClass = 'C';
            }

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
