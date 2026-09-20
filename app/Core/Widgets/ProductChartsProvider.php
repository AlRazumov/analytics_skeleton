<?php

namespace App\Core\Widgets;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\RankedTableData;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;
use App\Core\Widgets\DTO\TopProductRow;
use App\Core\Widgets\DTO\ValueRange;
use App\Support\Format;

/**
 * Данные графиков по товарам (распределения по корзинам, топ выручки).
 * Корзины считает БД (MetricsComparisonRepository::bucketCounts), здесь
 * только границы → диапазоны и подписи. Возвращает null, если данных
 * нет вовсе (страница показывает пустое состояние, график не рисуется).
 * Период — явный или последний из хранилища.
 */
final readonly class ProductChartsProvider
{
    private const string PRODUCT = 'product';

    public function __construct(private MetricsComparisonRepository $repository) {}

    /**
     * Оборачиваемость: 0, (0; b1), [b1; b2), …, [bn; ∞).
     *
     * @param  list<float>  $bounds  границы по возрастанию, > 0
     */
    public function turnoverDistribution(?Period $period, array $bounds): ?LineChartData
    {
        $ranges = [ValueRange::exactly(0.0)];
        $labels = ['0'];
        $previous = 0.0;
        foreach ($bounds as $bound) {
            $ranges[] = $previous === 0.0 ? ValueRange::open(0.0, $bound) : ValueRange::halfOpen($previous, $bound);
            $labels[] = Format::num($previous).'–'.Format::num($bound);
            $previous = $bound;
        }
        $ranges[] = $previous === 0.0 ? ValueRange::open(0.0, null) : ValueRange::halfOpen($previous, null);
        $labels[] = Format::num($previous).'+';

        return $this->chart('Распределение оборачиваемости (число товаров)', 'Товаров', 'turnover', self::PRODUCT, $period, $ranges, $labels);
    }

    /**
     * Неликвиды по возрасту (дней без продаж): [threshold; b1), …, [bn; ∞).
     * Значение no_sales_in_lookback — нижняя граница, товар относится к
     * корзине по ней же.
     *
     * @param  list<int>  $bounds
     */
    public function deadStockAge(?Period $period, int $thresholdDays, array $bounds): ?LineChartData
    {
        [$ranges, $labels] = $this->integerBuckets($thresholdDays, $bounds);

        return $this->chart('Неликвиды по возрасту, дней без продаж (число товаров)', 'Товаров', DeadStockCalculator::METRIC_KEY, DeadStockCalculator::ENTITY_TYPE, $period, $ranges, $labels);
    }

    /**
     * Дни до обнуления: [0; b1), …, [bn; ∞) — подписи по полным дням.
     *
     * @param  list<int>  $bounds
     */
    public function daysOfStock(?Period $period, array $bounds): ?LineChartData
    {
        [$ranges, $labels] = $this->integerBuckets(0, $bounds);

        return $this->chart('Дни до обнуления (число пар товар × склад)', 'Пар товар × склад', DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period, $ranges, $labels);
    }

    /**
     * Топ-N выручки из уже полученной таблицы (без запроса).
     *
     * @param  RankedTableData<TopProductRow>  $top
     */
    public function topRevenue(RankedTableData $top): ?LineChartData
    {
        if ($top->rows === []) {
            return null;
        }

        return new LineChartData('Топ товаров по выручке', [new Series('Выручка', array_map(
            static fn ($row) => new SeriesPoint($row->productName, $row->value),
            $top->rows,
        ))]);
    }

    /**
     * @param  list<int>  $bounds
     * @return array{list<ValueRange>, list<string>}
     */
    private function integerBuckets(int $first, array $bounds): array
    {
        $ranges = [];
        $labels = [];
        $from = $first;
        foreach ($bounds as $bound) {
            $ranges[] = ValueRange::halfOpen($from, $bound);
            $labels[] = $from.'–'.($bound - 1);
            $from = $bound;
        }
        $ranges[] = ValueRange::halfOpen($from, null);
        $labels[] = $from.'+';

        return [$ranges, $labels];
    }

    /**
     * @param  list<ValueRange>  $ranges
     * @param  list<string>  $labels
     */
    private function chart(string $title, string $seriesName, string $metricKey, string $entityType, ?Period $period, array $ranges, array $labels): ?LineChartData
    {
        $period ??= $this->repository->latestPeriod($metricKey, PeriodGranularity::Month);
        if ($period === null) {
            return null;
        }

        $counts = $this->repository->bucketCounts($metricKey, $entityType, $period, $ranges);
        if (array_sum($counts) === 0) {
            return null;
        }

        return new LineChartData($title, [new Series($seriesName, array_map(
            static fn (string $label, int $count) => new SeriesPoint($label, (float) $count),
            $labels,
            $counts,
        ))]);
    }
}
