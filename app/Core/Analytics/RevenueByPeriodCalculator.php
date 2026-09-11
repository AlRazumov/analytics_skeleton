<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Выручка по (товар, месяц) из fetchDeals(). Один снэпшот на пару
 * (productId, 'Y-m'), metric_key='revenue'.
 */
final class RevenueByPeriodCalculator
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'revenue';

    /**
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array
    {
        $byProductAndMonth = [];
        foreach ($adapter->fetchDeals($period) as $deal) {
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
                    period: $month,
                    valueMeta: [],
                );
            }
        }

        return $records;
    }
}
