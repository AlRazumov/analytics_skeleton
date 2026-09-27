<?php

namespace App\Core\Widgets;

use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Domain\PeriodRange;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\CategoryRow;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\MetricComparisonRow;
use App\Core\Widgets\DTO\RankedTableData;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;

/**
 * Данные страницы «Категории» поверх снэпшотов выручки категорий
 * (CategoryRevenueCalculator). Категория — сама себе подпись, справочник не
 * нужен; строка «без категории» показывается с подписью NO_CATEGORY_LABEL и
 * входит в долю и итог. Период — явный или последний из хранилища.
 */
final readonly class CategoryProvider
{
    public const string NO_CATEGORY_LABEL = 'Без категории';

    /** Потолок выборки: категорий на порядки меньше, чем товаров. */
    private const int ALL = 1000;

    public function __construct(
        private MetricsComparisonRepository $comparison,
        private MetricsSnapshotRepository $snapshots,
    ) {}

    /**
     * Все категории за месяц по убыванию выручки со сравнением с базой.
     *
     * @return RankedTableData<CategoryRow>
     */
    public function table(?Period $period, ComparisonBase $base = ComparisonBase::Previous): RankedTableData
    {
        $period ??= $this->comparison->latestPeriod(CategoryRevenueCalculator::METRIC_KEY, PeriodGranularity::Month);
        if ($period === null) {
            return new RankedTableData(null, [], 0);
        }

        $rows = $this->comparison->top(
            CategoryRevenueCalculator::METRIC_KEY, CategoryRevenueCalculator::ENTITY_TYPE, $period, self::ALL,
            RankBy::Value, Direction::Desc, $base,
        );
        $total = array_sum(array_map(static fn (MetricComparisonRow $r) => $r->value, $rows));

        return new RankedTableData($period->key(), array_map(
            static fn (MetricComparisonRow $r) => new CategoryRow(
                $r->entityId,
                self::label($r->entityId),
                $r->value,
                $total == 0.0 ? null : $r->value / $total * 100,
                $r->baseValue,
                $r->deltaAbs,
                $r->deltaPct,
                isset($r->valueMeta['products_sold']) ? (int) $r->valueMeta['products_sold'] : null,
            ),
            $rows,
        ), count($rows));
    }

    /** Есть ли у какой-нибудь категории выручка в базовом периоде. */
    public function hasBase(Period $period, ComparisonBase $base): bool
    {
        return $this->comparison->top(
            CategoryRevenueCalculator::METRIC_KEY, CategoryRevenueCalculator::ENTITY_TYPE, $period, 1,
            RankBy::DeltaAbs, Direction::Desc, $base,
        ) !== [];
    }

    /**
     * Выручка категорий за месяц (без запроса — из уже полученной таблицы).
     *
     * @param  RankedTableData<CategoryRow>  $table
     */
    public function revenueChart(RankedTableData $table): ?LineChartData
    {
        if ($table->rows === []) {
            return null;
        }

        return new LineChartData('Выручка по категориям', [new Series('revenue', array_map(
            static fn (CategoryRow $row) => new SeriesPoint($row->categoryName, $row->value),
            $table->rows,
        ))]);
    }

    /**
     * Помесячная выручка категорий за $months месяцев, заканчивающихся
     * $last: серия на категорию (по убыванию суммы за окно), месяц без
     * выручки — 0. null, если в окне нет ни одной строки.
     */
    public function monthlyChart(Period $last, int $months = 12): ?LineChartData
    {
        $range = new PeriodRange($last->start->modify('-'.($months - 1).' months'), $last->end);
        $keys = $range->keys();
        $records = $this->snapshots->findByPeriodKeys(CategoryRevenueCalculator::ENTITY_TYPE, CategoryRevenueCalculator::METRIC_KEY, $keys);
        if ($records === []) {
            return null;
        }

        $values = [];
        foreach ($records as $record) {
            $values[$record->entityId][$record->period] = ($values[$record->entityId][$record->period] ?? 0.0) + $record->value;
        }
        uksort($values, static fn ($a, $b) => array_sum($values[$b]) <=> array_sum($values[$a]) ?: strcmp((string) $a, (string) $b));

        $series = [];
        foreach ($values as $categoryId => $byPeriod) {
            $series[] = new Series(self::label((string) $categoryId), array_map(
                static fn (string $key) => new SeriesPoint(substr($key, (int) strpos($key, ':') + 1), $byPeriod[$key] ?? 0.0),
                $keys,
            ));
        }

        return new LineChartData('Выручка по категориям по месяцам', $series);
    }

    /** Подпись категории: ключ как есть, NO_CATEGORY — NO_CATEGORY_LABEL. */
    public static function label(string $categoryId): string
    {
        return $categoryId === CategoryRevenueCalculator::NO_CATEGORY ? self::NO_CATEGORY_LABEL : $categoryId;
    }
}
