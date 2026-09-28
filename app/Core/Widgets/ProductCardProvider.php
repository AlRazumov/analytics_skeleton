<?php

namespace App\Core\Widgets;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\Contracts\ProductCategoryResolver;
use App\Core\Widgets\Contracts\ProductMetricsRepository;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\Contracts\WarehouseNameResolver;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Core\Widgets\DTO\ProductCardData;
use App\Core\Widgets\DTO\ProductMonthRow;
use App\Core\Widgets\DTO\ProductWarehouseRow;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;

/**
 * Карточка товара: все его метрики за месяц и помесячная динамика. Только
 * чтение снэпшотов, к адаптеру не обращается. Месяц — явный или последний
 * с выручкой в хранилище (как на «Топе товаров»).
 *
 * «Рассчитанный месяц» — месяц, за который есть выручка хотя бы одного
 * товара: в нём отсутствие строки товара значит «продаж не было» (0), а в
 * нерассчитанном — «данных нет» (месяц не показывается).
 */
final readonly class ProductCardProvider
{
    private const string PRODUCT = 'product';

    private const string REVENUE_METRIC = 'revenue';

    private const string TURNOVER_METRIC = 'turnover';

    private const string LOST_SALES_METRIC = 'lost_sales';

    private const string ABC_XYZ_METRIC = 'abc_xyz_classification';

    public function __construct(
        private ProductMetricsRepository $metrics,
        private MetricsSnapshotRepository $snapshots,
        private MetricsComparisonRepository $comparison,
        private ProductNameResolver $names,
        private ProductCategoryResolver $categories,
        private WarehouseNameResolver $warehouseNames,
    ) {}

    /**
     * @param  int  $historyMonths  длина окна динамики, включая выбранный месяц
     * @return ?ProductCardData null — товара нет в справочнике
     */
    public function card(string $productId, ?Period $period, int $historyMonths): ?ProductCardData
    {
        $name = $this->names->names([$productId])[$productId] ?? null;
        if ($name === null) {
            return null;
        }

        [$abcClass, $xyzClass, $abcXyzPeriod] = $this->abcXyz($productId);
        $period ??= $this->comparison->latestPeriod(self::REVENUE_METRIC, PeriodGranularity::Month);
        if ($period === null) {
            return new ProductCardData(
                $productId, $name, $this->categories->categoryOf($productId), null, null, null, null, false, null, null,
                $abcClass, $xyzClass, $abcXyzPeriod, [], [],
            );
        }

        // Окно — выбранный месяц и $historyMonths − 1 предыдущих; выручка
        // читается и за предыдущий месяц (база изменения), даже если окно — один месяц.
        $keys = [];
        for ($month = $period, $i = 0; $i < max(1, $historyMonths); $month = $month->previous(), $i++) {
            $keys[] = $month->key();
        }
        $keys = array_reverse($keys);
        $previousKey = $period->previous()->key();
        $allKeys = array_values(array_unique([$previousKey, ...$keys]));

        $calculated = $this->snapshots->sumsByPeriod(self::PRODUCT, self::REVENUE_METRIC, $allKeys);
        $revenue = $this->metrics->forProduct(self::REVENUE_METRIC, $productId, $allKeys);
        $turnover = $this->metrics->forProduct(self::TURNOVER_METRIC, $productId, $keys);

        $history = [];
        foreach ($keys as $key) {
            if (! isset($calculated[$key]) && ! isset($turnover[$key])) {
                continue;
            }
            $meta = $turnover[$key]->valueMeta ?? [];
            $history[] = new ProductMonthRow(
                $key,
                $revenue[$key]->value ?? 0.0,
                self::metaFloat($meta, 'units_sold'),
                self::metaFloat($meta, 'closing_stock'),
                $turnover[$key]->value ?? null,
            );
        }

        $current = $period->key();
        $month = $history !== [] && end($history)->period === $current ? end($history) : null;
        $deadStock = $this->metrics->forProduct(DeadStockCalculator::METRIC_KEY, $productId, [$current])[$current] ?? null;
        $lostSales = $this->metrics->forProduct(self::LOST_SALES_METRIC, $productId, [$current])[$current] ?? null;
        $lostFrom = $lostSales?->valueMeta['last_sale_period'] ?? null;

        return new ProductCardData(
            $productId,
            $name,
            $this->categories->categoryOf($productId),
            $current,
            $month,
            isset($calculated[$previousKey]) ? ($revenue[$previousKey]->value ?? 0.0) : null,
            $deadStock?->value,
            ($deadStock?->valueMeta['no_sales_in_lookback'] ?? false) === true,
            $lostSales?->value,
            is_string($lostFrom) ? $lostFrom : null,
            $abcClass,
            $xyzClass,
            $abcXyzPeriod,
            $history,
            $this->warehouses($productId, $current),
        );
    }

    /** График выручки по месяцам окна; null — месяцев нет. */
    public function revenueChart(ProductCardData $card): ?LineChartData
    {
        if ($card->history === []) {
            return null;
        }

        return new LineChartData('Выручка по месяцам', [new Series(self::REVENUE_METRIC, array_map(
            static fn (ProductMonthRow $row) => new SeriesPoint(substr($row->period, strpos($row->period, ':') + 1), $row->revenue),
            $card->history,
        ))]);
    }

    /** @return array{?string, ?string, ?string} [ABC, XYZ, период расчёта] */
    private function abcXyz(string $productId): array
    {
        $period = $this->snapshots->latestPeriodFor(self::PRODUCT, self::ABC_XYZ_METRIC);
        if ($period === null) {
            return [null, null, null];
        }

        $meta = $this->metrics->forProduct(self::ABC_XYZ_METRIC, $productId, [$period])[$period]->valueMeta ?? null;
        if ($meta === null) {
            return [null, null, $period];
        }

        $abc = $meta['abc_class'] ?? null;
        $xyz = $meta['xyz_class'] ?? null;

        return [is_string($abc) ? $abc : null, is_string($xyz) ? $xyz : null, $period];
    }

    /**
     * Склады с продажами (days_of_stock, по возрастанию дней) и затем склады
     * с остатком без продаж (stock_no_demand, по убыванию остатка).
     *
     * @return list<ProductWarehouseRow>
     */
    private function warehouses(string $productId, string $periodKey): array
    {
        $withDemand = $this->metrics->forProductWarehouses(DaysOfStockCalculator::METRIC_KEY, $productId, $periodKey);
        $noDemand = $this->metrics->forProductWarehouses(DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY, $productId, $periodKey);
        usort($withDemand, static fn (MetricsSnapshotRecord $a, MetricsSnapshotRecord $b) => $a->value <=> $b->value);
        usort($noDemand, static fn (MetricsSnapshotRecord $a, MetricsSnapshotRecord $b) => $b->value <=> $a->value);

        $warehouseId = static fn (MetricsSnapshotRecord $r) => ProductWarehouseKey::parse($r->entityId)[1];
        $names = $this->warehouseNames->names(array_values(array_unique(array_map($warehouseId, [...$withDemand, ...$noDemand]))));

        $rows = [];
        foreach ($withDemand as $r) {
            $id = $warehouseId($r);
            $rows[] = new ProductWarehouseRow($id, $names[$id] ?? $id, self::metaFloat($r->valueMeta, 'stock_qty'), self::metaFloat($r->valueMeta, 'daily_rate'), $r->value);
        }
        foreach ($noDemand as $r) {
            $id = $warehouseId($r);
            $rows[] = new ProductWarehouseRow($id, $names[$id] ?? $id, $r->value, null, null);
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $meta */
    private static function metaFloat(array $meta, string $key): ?float
    {
        return isset($meta[$key]) && is_numeric($meta[$key]) ? (float) $meta[$key] : null;
    }
}
