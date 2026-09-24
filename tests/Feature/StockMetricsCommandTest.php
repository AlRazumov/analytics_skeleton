<?php

use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Models\MetricsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stockMetricRows(): array
{
    return MetricsSnapshot::query()
        ->whereIn('metric_key', ['days_since_last_sale', 'days_of_stock'])
        ->orderBy('metric_key')->orderBy('entity_id')->orderBy('period_start')
        ->get(['entity_type', 'entity_id', 'metric_key', 'value', 'value_meta', 'period_type', 'period_start'])
        ->map(fn ($r) => $r->toArray())
        ->all();
}

it('recalculates the same months idempotently: no unique-index violation and identical values', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-06:2026-08'])->assertExitCode(0);
    $first = stockMetricRows();

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-06:2026-08'])->assertExitCode(0);
    $second = stockMetricRows();

    expect($first)->not->toBeEmpty()
        ->and(collect($first)->pluck('metric_key')->unique()->sort()->values()->all())->toBe(['days_of_stock', 'days_since_last_sale'])
        ->and(collect($first)->pluck('entity_type')->unique()->sort()->values()->all())->toBe(['product', 'product_warehouse'])
        ->and($second)->toEqual($first);
});

it('replaces only the recalculated months on a backfill of a narrower period', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-06:2026-08'])->assertExitCode(0);
    $before = MetricsSnapshot::query()->where('metric_key', 'days_of_stock')->whereDate('period_start', '2026-06-01')->count();

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->assertExitCode(0);

    expect(MetricsSnapshot::query()->where('metric_key', 'days_of_stock')->whereDate('period_start', '2026-06-01')->count())->toBe($before);
});

it('defaults the period to the 12 months ending at the mock history end, not at today', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small'])
        ->expectsOutputToContain('период=2025-09-01..2026-08-31')
        ->assertExitCode(0);

    $months = MetricsSnapshot::query()->where('metric_key', 'revenue')->distinct()->orderBy('period_start')->pluck('period_start')
        ->map(fn ($d) => substr((string) $d, 0, 7))->all();

    expect($months[0])->toBe('2025-09')
        ->and(end($months))->toBe('2026-08');
});

it('keeps an explicit --period untouched by the mock-based default', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-05:2026-06'])
        ->expectsOutputToContain('период=2026-05-01..2026-06-30')
        ->assertExitCode(0);
});

it('overwrites OLD turnover values of the recalculated month without hitting the unique index', function () {
    // Значение, как его мог оставить прежний расчёт (сальдо от нуля).
    MetricsSnapshot::query()->create([
        'entity_type' => 'product', 'entity_id' => 'prod-3', 'metric_key' => 'turnover', 'value' => 999.0,
        'period_type' => 'month', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
    ]);

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->assertExitCode(0);

    $rows = MetricsSnapshot::query()->where('metric_key', 'turnover')->where('entity_id', 'prod-3')->get();

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->value)->not->toBe(999.0)
        ->and(collect($rows[0]->value_meta)->keys()->sort()->values()->all())->toBe(['avg_stock', 'closing_stock', 'opening_stock', 'units_sold']);
});

it('keeps the previous snapshots when writing the new ones fails', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->assertExitCode(0);
    $before = MetricsSnapshot::query()->count();

    app()->instance(MetricsSnapshotWriter::class, new class implements MetricsSnapshotWriter
    {
        public function write(array $records): void
        {
            MetricsSnapshot::query()->insert([
                'entity_type' => 'product', 'entity_id' => 'partial', 'metric_key' => 'revenue', 'value' => 1.0,
                'period_type' => 'month', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            throw new RuntimeException('write failed');
        }
    });

    expect(fn () => $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->run())
        ->toThrow(RuntimeException::class, 'write failed')
        ->and(MetricsSnapshot::query()->count())->toBe($before)
        ->and(MetricsSnapshot::query()->where('entity_id', 'partial')->exists())->toBeFalse();
});
