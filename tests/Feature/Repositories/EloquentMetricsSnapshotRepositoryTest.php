<?php

use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;
use App\Repositories\EloquentMetricsSnapshotRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('picks the largest period_start, not the snapshot written last', function () {
    // Снэпшот A: посчитан первым (дефолтный прогон), у него больший period_start.
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

    // Снэпшот B: посчитан позже (бэкфилл старого окна), период меньше — «последним» не становится.
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

    expect($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBe('month:2026-09');
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

it('sums values per period in the database, only for the requested entity type and periods', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 10.5, 'month:2026-07'),
        new MetricsSnapshotRecord('product', 'p2', 'revenue', 4.5, 'month:2026-07'),
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 7.0, 'month:2026-08'),
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 99.0, 'month:2026-06'),
        new MetricsSnapshotRecord('category', 'c1', 'revenue', 1000.0, 'month:2026-07'),
    ]);

    expect((new EloquentMetricsSnapshotRepository)->sumsByPeriod('product', 'revenue', ['month:2026-07', 'month:2026-08', 'month:2026-09']))
        ->toEqual(['month:2026-07' => 15.0, 'month:2026-08' => 7.0]);
});

it('returns the top entities by window sum with per-period values, ties by entity_id bytewise', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'b', 'revenue', 5.0, 'month:2026-07'),
        new MetricsSnapshotRecord('product', 'b', 'revenue', 5.0, 'month:2026-08'),
        new MetricsSnapshotRecord('product', 'a', 'revenue', 10.0, 'month:2026-08'),
        new MetricsSnapshotRecord('product', 'Z', 'revenue', 10.0, 'month:2026-07'),
        new MetricsSnapshotRecord('product', 'c', 'revenue', 1.0, 'month:2026-07'),
        new MetricsSnapshotRecord('product', 'd', 'revenue', 500.0, 'month:2026-05'),
    ]);

    $top = (new EloquentMetricsSnapshotRepository)->topBySum('product', 'revenue', ['month:2026-07', 'month:2026-08'], 3);

    expect($top['total'])->toBe(4)
        ->and($top['rows'])->toEqual([
            ['entityId' => 'Z', 'byPeriod' => ['month:2026-07' => 10.0]],
            ['entityId' => 'a', 'byPeriod' => ['month:2026-08' => 10.0]],
            ['entityId' => 'b', 'byPeriod' => ['month:2026-07' => 5.0, 'month:2026-08' => 5.0]],
        ]);
});

it('groups cells by two value_meta keys in the database, missing keys as "?"', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'p1', 'abc_xyz_classification', 100.0, 'month:2026-08', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'p2', 'abc_xyz_classification', 50.0, 'month:2026-08', ['abc_class' => 'A', 'xyz_class' => 'X']),
        new MetricsSnapshotRecord('product', 'p3', 'abc_xyz_classification', 5.0, 'month:2026-08', ['abc_class' => 'C']),
        new MetricsSnapshotRecord('product', 'p4', 'abc_xyz_classification', 1.0, 'month:2026-07', ['abc_class' => 'B', 'xyz_class' => 'Y']),
    ]);

    $cells = (new EloquentMetricsSnapshotRepository)->cellsByMeta('product', 'abc_xyz_classification', 'month:2026-08', 'abc_class', 'xyz_class');

    expect(array_map(fn ($c) => [$c->rowKey, $c->colKey, $c->itemsCount, $c->value], $cells))
        ->toBe([['A', 'X', 2, 150.0], ['C', '?', 1, 5.0]]);
});
