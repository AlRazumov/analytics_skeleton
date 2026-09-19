<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Выручка по (товар, месяц) из набора Deal (см. DataSourceAdapter::fetchDeals()).
 * Один снэпшот на пару (productId, 'Y-m'), metric_key='revenue'.
 */
final class RevenueByPeriodCalculator
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'revenue';

    /**
     * @param  iterable<Deal>  $deals
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, DateRange $period): array
    {
        $byProductAndMonth = [];
        foreach ($deals as $deal) {
            $month = $deal->date->format('Y-m');
            $byProductAndMonth[$deal->productId][$month] = ($byProductAndMonth[$deal->productId][$month] ?? 0.0) + $deal->amount;
        }

        $records = [];
        foreach ($byProductAndMonth as $productId => $byMonth) {
            foreach ($byMonth as $month => $value) {
                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: $productId,
                    metricKey: self::METRIC_KEY,
                    value: $value,
                    period: 'month:'.$month,
                    valueMeta: [],
                );
            }
        }

        return $records;
    }
}
