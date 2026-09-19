<?php

namespace App\Core\Widgets;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\Contracts\WarehouseNameResolver;
use App\Core\Widgets\DTO\DeadStockRow;
use App\Core\Widgets\DTO\MetricComparisonRow;
use App\Core\Widgets\DTO\RankedTableData;
use App\Core\Widgets\DTO\StockoutRiskRow;
use App\Core\Widgets\DTO\TopProductRow;

/**
 * Данные табличных виджетов по товарам (неликвиды, риск дефицита,
 * топ/анти-топ) поверх MetricsComparisonRepository. Не знает об
 * источнике данных: названия — через ProductNameResolver, один вызов на
 * виджет. Период — явный или последний из хранилища (latestPeriod), без
 * текущего времени. Пороги и лимиты приходят аргументами.
 */
final readonly class ProductTablesProvider
{
    private const string REVENUE_METRIC = 'revenue';

    private const string PRODUCT = 'product';

    public function __construct(
        private MetricsComparisonRepository $repository,
        private ProductNameResolver $names,
        private WarehouseNameResolver $warehouseNames,
    ) {}

    /**
     * Товары с days_since_last_sale >= $thresholdDays, по убыванию.
     *
     * @return RankedTableData<DeadStockRow>
     */
    public function deadStock(?Period $period, int $thresholdDays, int $limit): RankedTableData
    {
        $period ??= $this->repository->latestPeriod(DeadStockCalculator::METRIC_KEY, PeriodGranularity::Month);
        if ($period === null) {
            return new RankedTableData(null, [], 0);
        }

        $min = (float) $thresholdDays;
        $rows = $this->repository->top(
            DeadStockCalculator::METRIC_KEY, DeadStockCalculator::ENTITY_TYPE, $period, $limit,
            RankBy::Value, Direction::Desc, null, $min,
        );
        $total = $this->repository->count(DeadStockCalculator::METRIC_KEY, DeadStockCalculator::ENTITY_TYPE, $period, $min);

        $names = $this->names->names(array_map(static fn (MetricComparisonRow $r) => $r->entityId, $rows));

        return new RankedTableData($period->key(), array_map(
            fn (MetricComparisonRow $r) => new DeadStockRow(
                $r->entityId,
                $names[$r->entityId] ?? $r->entityId,
                self::metaFloat($r, 'stock_qty'),
                $r->value,
                ($r->valueMeta['no_sales_in_lookback'] ?? false) === true,
            ),
            $rows,
        ), $total);
    }

    /**
     * Пары товар × склад с days_of_stock <= $thresholdDays, по возрастанию.
     *
     * @return RankedTableData<StockoutRiskRow>
     */
    public function stockoutRisk(?Period $period, int $thresholdDays, int $limit): RankedTableData
    {
        $period ??= $this->repository->latestPeriod(DaysOfStockCalculator::METRIC_KEY, PeriodGranularity::Month);
        if ($period === null) {
            return new RankedTableData(null, [], 0);
        }

        $max = (float) $thresholdDays;
        $rows = $this->repository->top(
            DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period, $limit,
            RankBy::Value, Direction::Asc, null, null, $max,
        );
        $total = $this->repository->count(DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period, null, $max);

        $pairs = array_map(static fn (MetricComparisonRow $r) => ProductWarehouseKey::parse($r->entityId), $rows);
        $names = $this->names->names(array_values(array_unique(array_column($pairs, 0))));
        $warehouseNames = $this->warehouseNames->names(array_values(array_unique(array_column($pairs, 1))));

        $result = [];
        foreach ($rows as $i => $r) {
            [$productId, $warehouseId] = $pairs[$i];
            $result[] = new StockoutRiskRow(
                $productId,
                $names[$productId] ?? $productId,
                $warehouseId,
                $warehouseNames[$warehouseId] ?? $warehouseId,
                self::metaFloat($r, 'stock_qty'),
                self::metaFloat($r, 'daily_rate'),
                $r->value,
            );
        }

        return new RankedTableData($period->key(), $result, $total);
    }

    /**
     * Топ ($dir = Desc) / анти-топ ($dir = Asc) товаров по выручке за месяц
     * со сравнением с предыдущим месяцем.
     *
     * @return RankedTableData<TopProductRow>
     */
    public function topProducts(?Period $period, Direction $dir, int $limit): RankedTableData
    {
        $period ??= $this->repository->latestPeriod(self::REVENUE_METRIC, PeriodGranularity::Month);
        if ($period === null) {
            return new RankedTableData(null, [], 0);
        }

        $rows = $this->repository->top(
            self::REVENUE_METRIC, self::PRODUCT, $period, $limit, RankBy::Value, $dir, ComparisonBase::Previous,
        );
        $total = $this->repository->count(self::REVENUE_METRIC, self::PRODUCT, $period);

        $names = $this->names->names(array_map(static fn (MetricComparisonRow $r) => $r->entityId, $rows));

        return new RankedTableData($period->key(), array_map(
            fn (MetricComparisonRow $r) => new TopProductRow(
                $r->entityId, $names[$r->entityId] ?? $r->entityId, $r->value, $r->baseValue, $r->deltaAbs, $r->deltaPct,
            ),
            $rows,
        ), $total);
    }

    private static function metaFloat(MetricComparisonRow $row, string $key): ?float
    {
        return isset($row->valueMeta[$key]) ? (float) $row->valueMeta[$key] : null;
    }
}
