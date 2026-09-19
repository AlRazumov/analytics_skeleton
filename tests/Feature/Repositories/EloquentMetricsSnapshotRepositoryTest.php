<?php

use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;
use App\Repositories\EloquentMetricsSnapshotRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('picks the snapshot written last, not the one with the numerically largest period', function () {
    // Снэпшот A: посчитан первым (дефолтный прогон), но у него больший period.
    MetricsSnapshot::query()->create([
        'entity_type' => 'product',
        'entity_id' => 'p1',
        'metric_key' => 'abc_xyz_classification',
        'value' => 1,
        'value_meta' => ['abc_class' => 'A', 'xyz_class' => 'X'],
        'period_type' => 'month',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    // Снэпшот B: посчитан позже (бэкфилл старого окна), период меньше.
    MetricsSnapshot::query()->create([
        'entity_type' => 'product',
        'entity_id' => 'p2',
        'metric_key' => 'abc_xyz_classification',
        'value' => 2,
        'value_meta' => ['abc_class' => 'B', 'xyz_class' => 'Y'],
        'period_type' => 'month',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repository = new EloquentMetricsSnapshotRepository;

    expect($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBe('month:2026-06');
});

it('returns null when no snapshots exist', function () {
    $repository = new EloquentMetricsSnapshotRepository;

    expect($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBeNull();
});

it('roundtrips records of different granularities through writer and repository', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 10.0, 'month:2026-02'),
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 7.0, 'quarter:2026-Q1'),
    ]);

    $row = MetricsSnapshot::query()->where('period_type', 'month')->firstOrFail();
    expect($row->period_start->toDateString())->toBe('2026-02-01')
        ->and($row->period_end->toDateString())->toBe('2026-02-28');

    $found = (new EloquentMetricsSnapshotRepository)
        ->findByPeriodKeys('product', 'revenue', ['month:2026-02', 'quarter:2026-Q1', 'month:2026-03']);

    expect(collect($found)->pluck('period')->all())->toBe(['quarter:2026-Q1', 'month:2026-02']);
});

it('rejects a duplicate snapshot for the same entity, metric and period', function () {
    $record = new MetricsSnapshotRecord('product', 'p1', 'revenue', 10.0, 'month:2026-02');
    $writer = new EloquentMetricsSnapshotWriter;

    $writer->write([$record]);
    $writer->write([$record]);
})->throws(QueryException::class);
