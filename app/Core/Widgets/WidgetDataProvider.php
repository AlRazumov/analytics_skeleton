<?php

namespace App\Core\Widgets;

use App\Core\Domain\PeriodRange;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\DTO\KpiCardData;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\MatrixCellData;
use App\Core\Widgets\DTO\MatrixData;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;
use App\Core\Widgets\DTO\TableData;

/**
 * Собирает DTO виджетов из metrics_snapshots. Не знает ни об одном
 * адаптере — все данные идут через MetricsSnapshotRepository.
 * Один метод на тип виджета: разные виджеты имеют разную форму
 * агрегации, поэтому один "универсальный" билдер с флагами читался бы
 * хуже, чем явные методы.
 */
final readonly class WidgetDataProvider
{
    /**
     * entity_type/metric_key связки для ABC/XYZ-матрицы фиксированы
     * здесь: сама матрица — не срез по произвольной метрике, а
     * конкретный виджет классификации товаров.
     */
    private const string ABC_XYZ_ENTITY_TYPE = 'product';

    private const string ABC_XYZ_METRIC_KEY = 'abc_xyz_classification';

    public function __construct(
        private MetricsSnapshotRepository $repository,
        private ?ProductNameResolver $productNames = null,
    ) {}

    public function lineChart(string $entityType, string $metricKey, PeriodRange $period): LineChartData
    {
        $points = $this->pointsFor($entityType, $metricKey, $period);

        return new LineChartData(
            title: $metricKey,
            series: [new Series($metricKey, $points)],
        );
    }

    /**
     * Тот же метрик за два периода (текущий и год назад), выровненные
     * по позиции точки — отдельный метод, а не флаг внутри lineChart,
     * потому что форма результата (две серии вместо одной) другая.
     *
     * Если за год назад нет ни одного снэпшота (история короче двух
     * лет), серия прошлого года не добавляется: сплошной ноль выглядел
     * бы как «продаж не было», а не как «данных нет».
     */
    public function lineChartYoY(string $entityType, string $metricKey, PeriodRange $period): LineChartData
    {
        $previous = $period->previousYear();

        $series = [new Series($this->periodLabel($period), $this->pointsFor($entityType, $metricKey, $period))];

        $previousSums = $this->repository->sumsByPeriod($entityType, $metricKey, $previous->keys());
        if ($previousSums !== []) {
            $series[] = new Series($this->periodLabel($previous), $this->pointsFrom($previousSums, $previous, useLabelsFrom: $period));
        }

        return new LineChartData(title: $metricKey, series: $series);
    }

    /**
     * Первые $limit сущностей по сумме метрики за окно $period: строка —
     * сущность, колонки — периоды окна и итог за окно; «показано N из
     * total». Сортировка и LIMIT — в хранилище (topBySum), в память не
     * читаются все строки окна.
     *
     * @param  bool  $productNames  entity — товар: первая колонка «Товар» с названием
     *                              (одним вызовом резолвера на все id; нет названия — id)
     */
    public function topTable(string $entityType, string $metricKey, PeriodRange $period, int $limit, bool $productNames = false): TableData
    {
        $keys = $period->keys();
        $top = $this->repository->topBySum($entityType, $metricKey, $keys, $limit);

        $ids = array_map(static fn (array $row) => $row['entityId'], $top['rows']);
        $names = $productNames && $this->productNames !== null ? $this->productNames->names($ids) : [];

        $rows = [];
        foreach ($top['rows'] as $row) {
            $cells = [$names[$row['entityId']] ?? $row['entityId']];
            foreach ($keys as $key) {
                $cells[] = $row['byPeriod'][$key] ?? 0.0;
            }
            $cells[] = array_sum($row['byPeriod']);
            $rows[] = $cells;
        }

        return new TableData(
            headers: [$productNames ? 'Товар' : 'entity_id', ...array_map($this->displayLabel(...), $keys), 'Итого'],
            rows: $rows,
            total: $top['total'],
        );
    }

    public function kpiCard(string $entityType, string $metricKey, PeriodRange $period, ?string $unit = null): KpiCardData
    {
        $current = $this->sumFor($entityType, $metricKey, $period->keys());

        $previousKeys = $this->precedingPeriodKeys($period);
        $previous = $this->sumFor($entityType, $metricKey, $previousKeys);

        $deltaPercent = $previous !== 0.0
            ? round((($current - $previous) / abs($previous)) * 100, 2)
            : null;

        return new KpiCardData(
            label: $metricKey,
            value: $current,
            deltaPercent: $deltaPercent,
            unit: $unit,
        );
    }

    /**
     * Группирует снэпшоты ABC/XYZ-классификации (value_meta: abc_class,
     * xyz_class) по ячейкам матрицы. itemsCount — число сущностей в
     * ячейке, value — сумма их значений (например, выручки).
     *
     * Не принимает Period: ABC/XYZ-снэпшот не образует помесячную
     * серию (см. докблок MetricsSnapshotRepository::latestPeriodFor()),
     * поэтому "какой period запрашивать" — не решение вызывающего
     * кода, а знание репозитория. Раньше consumer передавал сюда
     * произвольный Period, и при его рассинхроне с периодом реального
     * прогона metrics:calculate матрица молча оказывалась пустой.
     */
    /**
     * Матрица ABC/XYZ за последний период. $category — только товары этой
     * категории справочника; классы при этом те же, что посчитаны по всему
     * ассортименту (фильтр, а не пересчёт Парето внутри категории).
     */
    public function abcXyzMatrix(?string $category = null): MatrixData
    {
        $latestPeriod = $this->repository->latestPeriodFor(self::ABC_XYZ_ENTITY_TYPE, self::ABC_XYZ_METRIC_KEY);

        if ($latestPeriod === null) {
            return new MatrixData([], [], []);
        }

        $cells = $this->repository->cellsByMeta(
            self::ABC_XYZ_ENTITY_TYPE,
            self::ABC_XYZ_METRIC_KEY,
            $latestPeriod,
            'abc_class',
            'xyz_class',
            $category,
        );

        $rowLabels = array_values(array_unique(array_map(static fn (MatrixCellData $c) => $c->rowKey, $cells)));
        $colLabels = array_values(array_unique(array_map(static fn (MatrixCellData $c) => $c->colKey, $cells)));
        sort($rowLabels);
        sort($colLabels);

        return new MatrixData($rowLabels, $colLabels, $cells);
    }

    /**
     * @return SeriesPoint[]
     */
    private function pointsFor(string $entityType, string $metricKey, PeriodRange $period): array
    {
        return $this->pointsFrom($this->repository->sumsByPeriod($entityType, $metricKey, $period->keys()), $period);
    }

    /**
     * @param  array<string, float>  $byPeriod  ключ периода => сумма (sumsByPeriod)
     * @return SeriesPoint[]
     */
    private function pointsFrom(array $byPeriod, PeriodRange $period, ?PeriodRange $useLabelsFrom = null): array
    {
        $keys = $period->keys();
        $labelKeys = $useLabelsFrom?->keys() ?? $keys;

        $points = [];
        foreach ($keys as $index => $key) {
            $points[] = new SeriesPoint($this->displayLabel($labelKeys[$index] ?? $key), $byPeriod[$key] ?? 0.0);
        }

        return $points;
    }

    /**
     * @param  string[]  $periodKeys
     */
    private function sumFor(string $entityType, string $metricKey, array $periodKeys): float
    {
        return array_sum($this->repository->sumsByPeriod($entityType, $metricKey, $periodKeys));
    }

    /**
     * Ключи периода той же длины, непосредственно предшествующего
     * переданному — используется как база сравнения для KPI-дельты.
     *
     * @return string[]
     */
    private function precedingPeriodKeys(PeriodRange $period): array
    {
        $keys = $period->keys();
        $length = count($keys);

        $unit = $period->granularity->value === 'day' ? 'day' : 'month';
        $precedingEnd = $period->start->modify("-1 {$unit}");
        $precedingStart = $precedingEnd->modify('-'.($length - 1)." {$unit}s");

        return (new PeriodRange($precedingStart, $precedingEnd, $period->granularity))->keys();
    }

    private function periodLabel(PeriodRange $period): string
    {
        $keys = $period->keys();

        return $this->displayLabel($keys[0] ?? 'month:'.$period->start->format('Y-m'));
    }

    /** Ключ периода без префикса гранулярности ('month:2026-01' → '2026-01'). */
    private function displayLabel(string $periodKey): string
    {
        return substr($periodKey, (int) strpos($periodKey, ':') + 1);
    }
}
