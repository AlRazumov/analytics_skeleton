<?php

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
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
    };
}

it('builds a line chart with one point per period, zero-filled when missing', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 100.0, '2026-01'),
        new MetricsSnapshotRecord('product', 'prod-2', 'revenue', 50.0, '2026-01'),
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 30.0, '2026-03'),
    ]);
    $provider = new WidgetDataProvider($repo);
    $period = new Period(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'), PeriodGranularity::Month);

    $data = $provider->lineChart('product', 'revenue', $period);

    expect($data->series)->toHaveCount(1);
    expect($data->series[0]->points)->toHaveCount(3);
    expect($data->series[0]->points[0]->value)->toBe(150.0);
    expect($data->series[0]->points[1]->value)->toBe(0.0);
    expect($data->series[0]->points[2]->value)->toBe(30.0);
});

it('builds abc/xyz matrix cells grouped by value_meta', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 100.0, '2026-01', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'prod-2', 'abc_xyz_classification', 200.0, '2026-01', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'prod-3', 'abc_xyz_classification', 10.0, '2026-01', ['abc_class' => 'C', 'xyz_class' => 'Z']),
    ]);
    $provider = new WidgetDataProvider($repo);
    $period = new Period(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $matrix = $provider->abcXyzMatrix($period);

    $axCell = collect($matrix->cells)->first(fn ($c) => $c->rowKey === 'A' && $c->colKey === 'X');
    expect($axCell->itemsCount)->toBe(2);
    expect($axCell->value)->toBe(300.0);
});

it('computes kpi delta against the preceding period of the same length', function () {
    $repo = fakeRepository([
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 100.0, '2026-02'),
        new MetricsSnapshotRecord('product', 'prod-1', 'revenue', 50.0, '2026-01'),
    ]);
    $provider = new WidgetDataProvider($repo);
    $period = new Period(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-02-28'), PeriodGranularity::Month);

    $kpi = $provider->kpiCard('product', 'revenue', $period);

    expect($kpi->value)->toBe(100.0);
    expect($kpi->deltaPercent)->toBe(100.0);
});
