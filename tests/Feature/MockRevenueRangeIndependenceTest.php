<?php

use App\Models\MetricsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, float> productId => revenue за месяц */
function monthRevenue(string $month): array
{
    return MetricsSnapshot::query()->where('metric_key', 'revenue')->where('period_start', $month.'-01')
        ->pluck('value', 'entity_id')->map(fn ($v) => (float) $v)->all();
}

it('gives identical revenue of a month for a one-month run and a whole-history run', function () {
    config(['analytics.mock.seed' => 1]);

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-06:2026-06'])->assertExitCode(0);
    $single = monthRevenue('2026-06');

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2025-09:2026-08'])->assertExitCode(0);
    $whole = monthRevenue('2026-06');

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-04:2026-06'])->assertExitCode(0);
    $quarter = monthRevenue('2026-06');

    expect($single)->not->toBeEmpty()->and(array_keys($whole))->toEqualCanonicalizing(array_keys($single))
        ->and(array_keys($quarter))->toEqualCanonicalizing(array_keys($single));
    foreach ($single as $product => $value) {
        expect($whole[$product])->toEqualWithDelta($value, 1e-9)
            ->and($quarter[$product])->toEqualWithDelta($value, 1e-9);
    }
});
