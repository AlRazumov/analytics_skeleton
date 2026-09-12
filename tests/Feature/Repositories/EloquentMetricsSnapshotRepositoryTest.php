<?php

use App\Models\MetricsSnapshot;
use App\Repositories\EloquentMetricsSnapshotRepository;
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
        'period' => '2026-09',
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
        'period' => '2026-06',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repository = new EloquentMetricsSnapshotRepository;

    expect($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBe('2026-06');
});

it('returns null when no snapshots exist', function () {
    $repository = new EloquentMetricsSnapshotRepository;

    expect($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBeNull();
});
