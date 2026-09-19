<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const SPLIT_PERIOD_MIGRATION = 'database/migrations/2026_09_19_100000_split_period_in_metrics_snapshots_table.php';

function snapshotIndexes(): array
{
    return collect(DB::select("select indexname from pg_indexes where tablename = 'metrics_snapshots'"))
        ->pluck('indexname')->all();
}

it('has the period triple and both indexes after migrating', function () {
    expect(Schema::hasColumns('metrics_snapshots', ['period_type', 'period_start', 'period_end']))->toBeTrue()
        ->and(Schema::hasColumn('metrics_snapshots', 'period'))->toBeFalse()
        ->and(snapshotIndexes())->toContain(
            'metrics_snapshots_entity_metric_period_unique',
            'metrics_snapshots_metric_period_value_index',
        );
});

it('rolls back and re-applies keeping existing rows', function () {
    DB::table('metrics_snapshots')->insert([
        ['entity_type' => 'product', 'entity_id' => 'p1', 'metric_key' => 'revenue', 'value' => 1,
            'period_type' => 'month', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28'],
        ['entity_type' => 'product', 'entity_id' => 'p1', 'metric_key' => 'revenue', 'value' => 2,
            'period_type' => 'day', 'period_start' => '2026-02-03', 'period_end' => '2026-02-03'],
    ]);

    Artisan::call('migrate:rollback', ['--path' => SPLIT_PERIOD_MIGRATION]);

    expect(Schema::hasColumn('metrics_snapshots', 'period'))->toBeTrue()
        ->and(Schema::hasColumn('metrics_snapshots', 'period_start'))->toBeFalse()
        ->and(DB::table('metrics_snapshots')->orderBy('id')->pluck('period')->all())->toBe(['2026-02', '2026-02-03']);

    Artisan::call('migrate', ['--path' => SPLIT_PERIOD_MIGRATION]);

    $rows = DB::table('metrics_snapshots')->orderBy('id')->get();
    expect($rows[0]->period_type)->toBe('month')
        ->and($rows[0]->period_end)->toBe('2026-02-28')
        ->and($rows[1]->period_type)->toBe('day')
        ->and($rows[1]->period_start)->toBe('2026-02-03');
});
