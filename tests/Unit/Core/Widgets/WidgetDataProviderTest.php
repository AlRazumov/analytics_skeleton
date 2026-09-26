<?php

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\PeriodRange;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\MatrixCellData;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Core\Widgets\WidgetDataProvider;

function fakeRepository(array $records): MetricsSnapshotRepository
{
    return new class($records) implements MetricsSnapshotRepository
    {
        public function __construct(private array $records) {}

        public function findByPeriodKeys(string $entityType, string $metricKey, array $periodKeys): array
        {
            return array_values(array_filter(
                $this->records,
                fn (MetricsSnapshotRecord $r) => $r->entityType === $entityType
                    && $r->metricKey === $metricKey
                    && in_array($r->period, $periodKeys, true),
            ));
        }

        public function sumsByPeriod(string $entityType, string $metricKey, array $periodKeys): array
        {
            $sums = [];
            foreach ($this->findByPeriodKeys($entityType, $metricKey, $periodKeys) as $r) {
                $sums[$r->period] = ($sums[$r->period] ?? 0.0) + $r->value;
            }

            return $sums;
        }

        public function topBySum(string $entityType, string $metricKey, array $periodKeys, int $limit): array
        {
            $byEntity = [];
            foreach ($this->findByPeriodKeys($entityType, $metricKey, $periodKeys) as $r) {
                $byEntity[$r->entityId][$r->period] = $r->value;
            }
            uksort($byEntity, fn ($a, $b) => array_sum($byEntity[$b]) <=> array_sum($byEntity[$a]) ?: strcmp($a, $b));
            $rows = [];
            foreach (array_slice($byEntity, 0, $limit, true) as $id => $byPeriod) {
                $rows[] = ['entityId' => (string) $id, 'byPeriod' => $byPeriod];
            }

            return ['rows' => $rows, 'total' => count($byEntity)];
        }

        public function cellsByMeta(string $entityType, string $metricKey, string $periodKey, string $rowMetaKey, string $colMetaKey): array
        {
            $cells = [];
            foreach ($this->findByPeriodKeys($entityType, $metricKey, [$periodKey]) as $r) {
                $row = (string) ($r->valueMeta[$rowMetaKey] ?? '?');
                $col = (string) ($r->valueMeta[$colMetaKey] ?? '?');
                $cell = $cells[$row.'|'.$col] ?? new MatrixCellData($row, $col, 0, 0.0);
                $cells[$row.'|'.$col] = new MatrixCellData($row, $col, $cell->itemsCount + 1, $cell->value + $r->value);
            }

            return array_values($cells);
        }

        public function latestPeriodFor(string $entityType, string $metricKey): ?string
        {
            $periods = array_map(
                fn (MetricsSnapshotRecord $r) => $r->period,
                array_filter(
                    $this->records,
                    fn (MetricsSnapshotRecord $r) => $r->entityType === $entityType && $r->metricKey === $metricKey,
                ),
            );

            if ($periods === []) {
                return null;
            }

            rsort($periods);

            return $periods[0];
        }
    };
}

it('builds a line chart with one point per period, zero-filled when missing', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 100.0, 'month:2026-01'),
        new MetricsSnapshotRecord('product', 'prod-2', 'revenue', 50.0, 'month:2026-01'),
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 30.0, 'month:2026-03'),
    ]);
    $provider = new WidgetDataProvider($repo);
    $period = new PeriodRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'), PeriodGranularity::Month);

    $data = $provider->lineChart('product', 'revenue', $period);

    expect($data->series)->toHaveCount(1);
    expect($data->series[0]->points)->toHaveCount(3);
    expect($data->series[0]->points[0]->value)->toBe(150.0);
    expect($data->series[0]->points[1]->value)->toBe(0.0);
    expect($data->series[0]->points[2]->value)->toBe(30.0);
});

it('builds abc/xyz matrix cells grouped by value_meta', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 100.0, 'month:2026-01', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'prod-2', 'abc_xyz_classification', 200.0, 'month:2026-01', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'prod-3', 'abc_xyz_classification', 10.0, 'month:2026-01', ['abc_class' => 'C', 'xyz_class' => 'Z']),
    ]);
    $provider = new WidgetDataProvider($repo);

    $matrix = $provider->abcXyzMatrix();

    $axCell = collect($matrix->cells)->first(fn ($c) => $c->rowKey === 'A' && $c->colKey === 'X');
    expect($axCell->itemsCount)->toBe(2);
    expect($axCell->value)->toBe(300.0);
});

it('ignores stale ABC/XYZ snapshots from an earlier metrics:calculate run and uses only the latest period', function () {
    // Регрессионный тест против бага "матрица пустая при рассинхроне
    // периода прогона и периода, который запрашивал consumer" (см.
    // docs/roadmap.md, был в разделе Known issues). abcXyzMatrix()
    // больше не принимает Period снаружи — сам находит актуальный
    // period через latestPeriodFor(), поэтому старые снэпшоты от
    // предыдущего прогона с другим диапазоном не должны примешиваться.
    $repo = fakeRepository([
        // Прошлый прогон metrics:calculate — диапазон закончился в 2025-12.
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 999.0, 'month:2025-12', ['abc_class' => 'C', 'xyz_class' => 'Z']),
        // Последний прогон — диапазон закончился в 2026-06.
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 100.0, 'month:2026-06', ['abc_class' => 'A', 'xyz_class' => 'X']),
    ]);
    $provider = new WidgetDataProvider($repo);

    $matrix = $provider->abcXyzMatrix();

    expect($matrix->cells)->toHaveCount(1);
    expect($matrix->cells[0]->rowKey)->toBe('A');
    expect($matrix->cells[0]->colKey)->toBe('X');
    expect($matrix->cells[0]->value)->toBe(100.0);
});

it('returns an empty matrix when there are no ABC/XYZ snapshots yet', function () {
    $repo = fakeRepository([]);
    $provider = new WidgetDataProvider($repo);

    $matrix = $provider->abcXyzMatrix();

    expect($matrix->rowLabels)->toBe([]);
    expect($matrix->colLabels)->toBe([]);
    expect($matrix->cells)->toBe([]);
});

it('computes kpi delta against the preceding period of the same length', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 100.0, 'month:2026-02'),
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 50.0, 'month:2026-01'),
    ]);
    $provider = new WidgetDataProvider($repo);
    $period = new PeriodRange(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-02-28'), PeriodGranularity::Month);

    $kpi = $provider->kpiCard('product', 'revenue', $period);

    expect($kpi->value)->toBe(100.0);
    expect($kpi->deltaPercent)->toBe(100.0);
});

it('builds the top table by window sum with one column per period and a total', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 100.0, 'month:2026-01'),
        new MetricsSnapshotRecord('product', 'prod-2', 'revenue', 50.0, 'month:2026-01'),
        new MetricsSnapshotRecord('product', 'prod-2', 'revenue', 80.0, 'month:2026-03'),
        new MetricsSnapshotRecord('product', 'prod-3', 'revenue', 10.0, 'month:2026-02'),
    ]);
    $period = new PeriodRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'), PeriodGranularity::Month);

    $table = (new WidgetDataProvider($repo))->topTable('product', 'revenue', $period, 2);

    expect($table->headers)->toBe(['entity_id', '2026-01', '2026-02', '2026-03', 'Итого'])
        ->and($table->rows)->toBe([
            ['prod-2', 50.0, 0.0, 80.0, 130.0],
            ['prod-1', 100.0, 0.0, 0.0, 100.0],
        ])
        ->and($table->total)->toBe(3);
});
