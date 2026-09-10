<?php

namespace App\Core\Widgets;

use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\KpiCardData;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\MatrixCellData;
use App\Core\Widgets\DTO\MatrixData;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
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
    ) {}

    public function lineChart(string $entityType, string $metricKey, Period $period): LineChartData
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
     */
    public function lineChartYoY(string $entityType, string $metricKey, Period $period): LineChartData
    {
        $previous = $period->previousYear();

        $currentPoints = $this->pointsFor($entityType, $metricKey, $period);
        $previousPoints = $this->pointsFor($entityType, $metricKey, $previous, useLabelsFrom: $period);

        return new LineChartData(
            title: $metricKey,
            series: [
                new Series($this->periodLabel($period), $currentPoints),
                new Series($this->periodLabel($previous), $previousPoints),
            ],
        );
    }

    public function table(string $entityType, string $metricKey, Period $period): TableData
    {
        $records = $this->repository->findByPeriodKeys($entityType, $metricKey, $period->keys());

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [$record->entityId, $record->period, $record->value];
        }

        return new TableData(
            headers: ['entity_id', 'period', $metricKey],
            rows: $rows,
        );
    }

    public function kpiCard(string $entityType, string $metricKey, Period $period, ?string $unit = null): KpiCardData
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
     */
    public function abcXyzMatrix(Period $period): MatrixData
    {
        $records = $this->repository->findByPeriodKeys(
            self::ABC_XYZ_ENTITY_TYPE,
            self::ABC_XYZ_METRIC_KEY,
            $period->keys(),
        );

        $cells = [];
        foreach ($records as $record) {
            $rowKey = (string) ($record->valueMeta['abc_class'] ?? '?');
            $colKey = (string) ($record->valueMeta['xyz_class'] ?? '?');
            $cellKey = $rowKey.'|'.$colKey;

            if (! isset($cells[$cellKey])) {
                $cells[$cellKey] = new MatrixCellData($rowKey, $colKey, 0, 0.0);
            }

            $cells[$cellKey] = new MatrixCellData(
                $rowKey,
                $colKey,
                $cells[$cellKey]->itemsCount + 1,
                $cells[$cellKey]->value + $record->value,
            );
        }

        $rowLabels = array_values(array_unique(array_map(static fn (MatrixCellData $c) => $c->rowKey, $cells)));
        $colLabels = array_values(array_unique(array_map(static fn (MatrixCellData $c) => $c->colKey, $cells)));
        sort($rowLabels);
        sort($colLabels);

        return new MatrixData($rowLabels, $colLabels, array_values($cells));
    }

    /**
     * @return SeriesPoint[]
     */
    private function pointsFor(string $entityType, string $metricKey, Period $period, ?Period $useLabelsFrom = null): array
    {
        $records = $this->repository->findByPeriodKeys($entityType, $metricKey, $period->keys());

        $byPeriod = [];
        foreach ($records as $record) {
            $byPeriod[$record->period] = ($byPeriod[$record->period] ?? 0.0) + $record->value;
        }

        $keys = $period->keys();
        $labelKeys = $useLabelsFrom?->keys() ?? $keys;

        $points = [];
        foreach ($keys as $index => $key) {
            $points[] = new SeriesPoint($labelKeys[$index] ?? $key, $byPeriod[$key] ?? 0.0);
        }

        return $points;
    }

    /**
     * @param  string[]  $periodKeys
     */
    private function sumFor(string $entityType, string $metricKey, array $periodKeys): float
    {
        $records = $this->repository->findByPeriodKeys($entityType, $metricKey, $periodKeys);

        return array_reduce($records, static fn (float $sum, MetricsSnapshotRecord $r) => $sum + $r->value, 0.0);
    }

    /**
     * Ключи периода той же длины, непосредственно предшествующего
     * переданному — используется как база сравнения для KPI-дельты.
     *
     * @return string[]
     */
    private function precedingPeriodKeys(Period $period): array
    {
        $keys = $period->keys();
        $length = count($keys);

        $unit = $period->granularity->value === 'day' ? 'day' : 'month';
        $precedingEnd = $period->start->modify("-1 {$unit}");
        $precedingStart = $precedingEnd->modify('-'.($length - 1)." {$unit}s");

        return (new Period($precedingStart, $precedingEnd, $period->granularity))->keys();
    }

    private function periodLabel(Period $period): string
    {
        $keys = $period->keys();

        return $keys[0] ?? $period->start->format('Y-m');
    }
}
