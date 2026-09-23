<?php

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Domain\Period;
use App\Core\Transfers\TransferDonorReason;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Services\TransferRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, float> $pairs "product:wh" => stock_qty */
function seedNoDemandStock(array $pairs, string $period = 'month:2026-08'): void
{
    $records = [];
    foreach ($pairs as $key => $stockQty) {
        $records[] = new MetricsSnapshotRecord(
            'product_warehouse', $key, DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY,
            $stockQty, $period, ['stock_qty' => $stockQty],
        );
    }
    (new EloquentMetricsSnapshotWriter)->write($records);
}

/** @param array<string, array{float, float}|null> $pairs "product:wh" => [stock, rate] (null — без value_meta) */
function seedDays(array $pairs, string $period = 'month:2026-08', string $metric = 'days_of_stock'): void
{
    $records = [];
    foreach ($pairs as $key => $data) {
        $records[] = new MetricsSnapshotRecord(
            'product_warehouse', $key, $metric,
            $data === null ? 5.0 : $data[0] / $data[1], $period,
            $data === null ? [] : ['stock_qty' => $data[0], 'daily_rate' => $data[1]],
        );
    }
    (new EloquentMetricsSnapshotWriter)->write($records);
}

function fetchedIds(string $period = 'month:2026-08', float $max = 14.0, string $metric = 'days_of_stock'): array
{
    $ids = [];
    foreach ((new EloquentMetricsComparisonRepository)->rowsOfProductsWithValueAtMost($metric, 'product_warehouse', Period::fromKey($period), $max) as $row) {
        $ids[] = $row->entityId;
    }

    return $ids;
}

it('returns all pairs of the products that have a deficit pair, and no other products', function () {
    seedDays([
        'a:w1' => [10, 5], 'a:w2' => [1000, 5],   // a: дефицит + донор
        'b:w1' => [1000, 5], 'b:w2' => [900, 5],  // b: дефицита нет
        'c:w1' => [70, 5], 'c:w2' => [500, 5],    // c: 14 дней ровно — дефицит (граница)
        'd:w1' => [75, 5],                        // d: 15 дней — нет
    ]);

    expect(fetchedIds())->toBe(['a:w1', 'a:w2', 'c:w1', 'c:w2']);
});

it('is not disturbed by another period or another metric key', function () {
    seedDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]], 'month:2026-07');
    seedDays(['x:w1' => [10, 5], 'x:w2' => [1000, 5]], 'month:2026-08', 'other_metric');
    seedDays(['b:w1' => [1000, 5], 'b:w2' => [1000, 5]]);

    expect(fetchedIds())->toBe([])
        ->and(fetchedIds('month:2026-07'))->toBe(['a:w1', 'a:w2'])
        ->and(fetchedIds('month:2026-08', 14.0, 'other_metric'))->toBe(['x:w1', 'x:w2']);
});

it('reads everything in one query', function () {
    seedDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5], 'b:w1' => [10, 5]]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    fetchedIds();

    expect($queries)->toBe(1);
});

it('works on an empty database', function () {
    expect(fetchedIds())->toBe([]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));
    expect($result->plan->recommendations)->toBe([])->and($result->plan->deficitPairs)->toBe(0)
        ->and($result->positionsRead)->toBe(0)->and($result->skippedWithoutMeta)->toBe(0);
});

it('skips rows without value_meta and counts them', function () {
    seedDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5], 'a:w3' => null]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));

    expect($result->skippedWithoutMeta)->toBe(1)->and($result->positionsRead)->toBe(2)
        ->and($result->plan->recommendations)->toHaveCount(1)
        ->and($result->plan->recommendations[0]->quantity)->toBe(140);
});

it('builds recommendations from the config thresholds', function () {
    seedDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]]);
    $period = Period::fromKey('month:2026-08');

    // По умолчанию: нужно 5*30-10 = 140.
    expect(app(TransferRecommendationService::class)->forPeriod($period)->plan->recommendations[0]->quantity)->toBe(140);

    config(['analytics.transfers.target_days' => 20, 'analytics.transfers.keep_days' => 20]);
    expect(app(TransferRecommendationService::class)->forPeriod($period)->plan->recommendations[0]->quantity)->toBe(90);
});

it('does not treat a warehouse without a days_of_stock row as a donor when it has no stock_no_demand row either', function () {
    // Дефицит на w1; на w2 остаток есть, но продаж нет → строки days_of_stock нет,
    // и метрика-эвристика stock_no_demand тоже не посчитана (например, старый прогон).
    seedDays(['a:w1' => [10, 5]]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));

    expect($result->plan->recommendations)->toBe([])
        ->and($result->plan->deficitPairs)->toBe(1)
        ->and($result->plan->unmatchedDeficits)->toBe(1);
});

it('treats a no-demand warehouse above the threshold as a stock_surplus donor', function () {
    // Дефицит: w1 (stock 10, rate 5) → 2 дня, нужно 5*30-10 = 140.
    seedDays(['a:w1' => [10, 5]]);
    // w2: остаток 300 без продаж, порог по умолчанию 20 → доступно 280.
    seedNoDemandStock(['a:w2' => 300]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));

    expect($result->plan->deficitPairs)->toBe(1)->and($result->plan->unmatchedDeficits)->toBe(0)
        ->and($result->plan->recommendations)->toHaveCount(1);

    $r = $result->plan->recommendations[0];
    expect($r->fromWarehouseId)->toBe('w2')->and($r->toWarehouseId)->toBe('w1')
        ->and($r->quantity)->toBe(140)
        ->and($r->reason)->toBe(TransferDonorReason::StockSurplus)
        ->and($r->fromCoverageBefore)->toBe(INF)->and($r->fromCoverageAfter)->toBe(INF);
});

it('ignores a no-demand warehouse at or below the stock_surplus threshold', function () {
    seedDays(['a:w1' => [10, 5]]);
    config(['analytics.transfers.stock_surplus_min_stock' => 50]);
    seedNoDemandStock(['a:w2' => 50]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));

    expect($result->plan->recommendations)->toBe([])->and($result->plan->unmatchedDeficits)->toBe(1);
});

it('ignores a no-demand row for a product with no deficit anywhere', function () {
    seedDays(['a:w1' => [1000, 5]]);
    seedNoDemandStock(['b:w2' => 300]);

    $result = app(TransferRecommendationService::class)->forPeriod(Period::fromKey('month:2026-08'));

    expect($result->plan->recommendations)->toBe([])->and($result->plan->deficitPairs)->toBe(0);
});
