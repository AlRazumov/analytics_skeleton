<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Deal;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Period;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Агрегатор по реестру метрик продавцов: идёт по SellerMetric, по одному
 * снэпшоту на (месяц, продавец, метрика) в общий пайплайн metrics_snapshots.
 * Продажи без продавца пишутся под SellerSalesData::NO_SELLER; их строки
 * несут value_meta['seller_on_deal'] — режим покрытия для виджета.
 * При SellerCoverage::None ничего не пишется (блока продавцов нет).
 */
final class SellerMetricsCalculator
{
    public const string ENTITY_TYPE = 'seller';

    /** Ключ value_meta строки «без продавца» с режимом покрытия. */
    public const string COVERAGE_META = 'seller_on_deal';

    /** @param  list<SellerMetric>  $metrics */
    public function __construct(private readonly array $metrics) {}

    /** @return list<SellerMetric> все встроенные метрики (реестр по умолчанию) */
    public static function builtIn(): array
    {
        return [new SalesCount, new SalesAmount, new AvgCheck, new ShareOfTotal, new SalesPerActiveDay, new Trend];
    }

    /**
     * @param  iterable<Deal>  $deals
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, SellerCoverage $coverage): array
    {
        if ($coverage === SellerCoverage::None) {
            return [];
        }

        $data = SellerSalesData::fromDeals($deals);
        $records = [];
        foreach ($data->months() as $month) {
            $period = Period::containing(PeriodGranularity::Month, new DateTimeImmutable($month.'-01'));
            foreach ($this->metrics as $metric) {
                foreach ($metric->compute($data, $period) as $entityId => $value) {
                    $records[] = new MetricsSnapshotRecord(
                        entityType: $metric->entityType(),
                        entityId: (string) $entityId,
                        metricKey: $metric->key(),
                        value: $value,
                        period: $period->key(),
                        valueMeta: $entityId === SellerSalesData::NO_SELLER ? [self::COVERAGE_META => $coverage->value] : [],
                    );
                }
            }
        }

        return $records;
    }
}
