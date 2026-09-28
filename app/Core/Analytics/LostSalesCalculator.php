<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use InvalidArgumentException;

/**
 * ЭВРИСТИКА ДЛЯ ДЕМО (не финальное продуктовое решение, см. docs/roadmap.md,
 * Known issues «потерянные продажи»): товар считается «потерявшим продажи»
 * в месяце M, если в M нет ни одной продажи, а в одном из $horizonMonths
 * месяцев до M продажи были. Итоговое решение о том, нужна ли такая
 * выборка и в каком виде, — за реальным клиентом.
 *
 * Продажа — сделка с положительной суммой; возвраты (отрицательные
 * сделки, так их допускает контракт) продажами не считаются и продажи не
 * гасят: месяц, где продажи погашены возвратами до нетто ≤ 0, не
 * «потерянный», а месяц только с возвратами — «потерянный».
 *
 * entity_type='product', metric_key='lost_sales'. Один снэпшот на пару
 * (товар, месяц), value — сумма продаж (без возвратов) ближайшего
 * предыдущего месяца из горизонта, где продажи были (оценка «сколько
 * теряем в месяц»: спрос, а не нетто, которое уменьшают возвраты
 * прошлых продаж).
 * value_meta: horizon_months, last_sale_period (ключ периода последнего
 * месяца с продажами).
 *
 * ОГРАНИЧЕНИЕ: «было» ищется только среди месяцев запрошенного
 * диапазона — для месяцев в начале диапазона видно меньше горизонта
 * (или вообще ничего) до его границы, что может занижать число
 * найденных потерь у самого начала диапазона, но не завышать (товар
 * без единой видимой продажи в горизонте не считается «потерявшим»,
 * а просто пропускается). Принимает уже полученный набор Deal (тот же,
 * что получают Revenue/Abc/XyzClassifier), к адаптеру не обращается —
 * fetchDeals() не вызывается повторно.
 */
final class LostSalesCalculator
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'lost_sales';

    public function __construct(private readonly int $horizonMonths = 1)
    {
        if ($horizonMonths < 1) {
            throw new InvalidArgumentException("horizonMonths должен быть >= 1, получено {$horizonMonths}.");
        }
    }

    /**
     * @param  iterable<Deal>  $deals
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, DateRange $period): array
    {
        // Сумма только продаж по (товар, месяц); месяц есть в массиве, только
        // если в нём была хотя бы одна продажа.
        $byProductAndMonth = [];
        foreach ($deals as $deal) {
            if ($deal->amount <= 0.0) {
                continue;
            }
            $month = $deal->date->format('Y-m');
            $byProductAndMonth[$deal->productId][$month] = ($byProductAndMonth[$deal->productId][$month] ?? 0.0) + $deal->amount;
        }

        $records = [];

        foreach (Months::in($period->start, $period->end) as $month) {
            $currentKey = $month->start->format('Y-m');

            foreach ($byProductAndMonth as $productId => $byMonth) {
                if (isset($byMonth[$currentKey])) {
                    continue;
                }

                $lastSaleMonth = null;
                $lastSaleAmount = 0.0;
                for ($back = 1; $back <= $this->horizonMonths; $back++) {
                    $prevKey = $month->start->modify("-{$back} months")->format('Y-m');
                    if (isset($byMonth[$prevKey])) {
                        $lastSaleMonth = $prevKey;
                        $lastSaleAmount = $byMonth[$prevKey];
                        break;
                    }
                }

                if ($lastSaleMonth === null) {
                    continue;
                }

                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: $productId,
                    metricKey: self::METRIC_KEY,
                    value: $lastSaleAmount,
                    period: 'month:'.$currentKey,
                    valueMeta: [
                        'horizon_months' => $this->horizonMonths,
                        'last_sale_period' => 'month:'.$lastSaleMonth,
                    ],
                );
            }
        }

        return $records;
    }
}
