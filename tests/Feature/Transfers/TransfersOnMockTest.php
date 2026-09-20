<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\Period;
use App\Core\Transfers\TransferRecommendation;
use App\Services\TransferRecommendationResult;
use App\Services\TransferRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Метрики через demo:install (реальные калькуляторы за всю историю мока),
 * затем рекомендации за последний месяц истории.
 *
 * @return array{MockAdapter, TransferRecommendationResult}
 */
function transfersOnMock(MockDataProfile $profile): array
{
    config(['analytics.mock.profile' => $profile->value, 'analytics.mock.seed' => 42]);
    test()->artisan('demo:install')->assertExitCode(0);

    $adapter = new MockAdapter($profile, 42);
    $period = Period::fromKey('month:'.substr($adapter->manifest()->historyEnd, 0, 7));

    return [$adapter, app(TransferRecommendationService::class)->forPeriod($period)];
}

/** Оракул: считается от чисел манифеста, планировщик не вызывается. */
function transferOracle(array $m): array
{
    $target = (float) config('analytics.transfers.target_days');
    $keep = (float) config('analytics.transfers.keep_days');
    $need = $m['deficit_daily_rate'] * $target - $m['deficit_stock_at_end'];
    $available = $m['surplus_stock_at_end'] - $m['surplus_daily_rate'] * $keep;
    $quantity = (int) floor(min($need, $available));

    return [
        'quantity' => $quantity,
        'fromBefore' => $m['surplus_stock_at_end'] / $m['surplus_daily_rate'],
        'fromAfter' => ($m['surplus_stock_at_end'] - $quantity) / $m['surplus_daily_rate'],
        'toBefore' => $m['deficit_stock_at_end'] / $m['deficit_daily_rate'],
        'toAfter' => ($m['deficit_stock_at_end'] + $quantity) / $m['deficit_daily_rate'],
    ];
}

$checks = function (MockDataProfile $profile) {
    [$adapter, $result] = transfersOnMock($profile);
    $manifest = $adapter->manifest();
    $recs = $result->plan->recommendations;

    // Каждый товар с дисбалансом получает ровно ту рекомендацию, что даёт оракул.
    expect($manifest->imbalanceProducts)->not->toBeEmpty();
    foreach ($manifest->imbalanceProducts as $productId => $m) {
        $found = array_values(array_filter($recs, fn (TransferRecommendation $r) => $r->productId === $productId
            && $r->fromWarehouseId === $m['surplus_warehouse_id'] && $r->toWarehouseId === $m['deficit_warehouse_id']));
        $expected = transferOracle($m);

        expect($found)->toHaveCount(1, "{$productId}: рекомендация есть");
        expect($found[0]->quantity)->toBe($expected['quantity'], "{$productId}: количество")
            ->and($found[0]->fromCoverageBefore)->toEqualWithDelta($expected['fromBefore'], 1e-9)
            ->and($found[0]->fromCoverageAfter)->toEqualWithDelta($expected['fromAfter'], 1e-9)
            ->and($found[0]->toCoverageBefore)->toEqualWithDelta($expected['toBefore'], 1e-9)
            ->and($found[0]->toCoverageAfter)->toEqualWithDelta($expected['toAfter'], 1e-9);
    }

    // nearZero: единственный склад с остатком по товару — рекомендаций нет (ни как получатель, ни как донор).
    $nearZeroIds = array_keys($manifest->nearZeroProducts);
    expect($nearZeroIds)->not->toBeEmpty();
    foreach ($recs as $r) {
        expect($nearZeroIds)->not->toContain($r->productId);
    }
    // ...и по данным у них нет второго склада с остатком: одна строка days_of_stock на товар.
    $month = substr($manifest->historyEnd, 0, 7).'-01';
    foreach ($nearZeroIds as $id) {
        expect(DB::table('metrics_snapshots')->where('metric_key', 'days_of_stock')->where('period_start', $month)
            ->where('entity_id', 'like', "{$id}:%")->count())->toBe(1);
    }

    // Инварианты на всех рекомендациях.
    $keep = (float) config('analytics.transfers.keep_days');
    $target = (float) config('analytics.transfers.target_days');
    foreach ($recs as $r) {
        expect($r->quantity)->toBeGreaterThanOrEqual((int) config('analytics.transfers.min_quantity'))
            ->and($r->fromWarehouseId)->not->toBe($r->toWarehouseId)
            ->and($r->fromCoverageAfter)->toBeGreaterThanOrEqual($keep - 1e-9)
            ->and($r->toCoverageAfter)->toBeLessThanOrEqual($target + 1e-9);
    }

    // Счётчики согласованы с данными.
    $deficitRows = DB::table('metrics_snapshots')->where('metric_key', 'days_of_stock')
        ->where('period_start', substr($manifest->historyEnd, 0, 7).'-01')->where('value', '<=', config('analytics.transfers.deficit_days'))->count();
    expect($result->plan->deficitPairs)->toBe($deficitRows)->and($result->skippedWithoutMeta)->toBe(0);
};

it('recommends the imbalance scenario exactly as the manifest oracle says and keeps the invariants (Small)', fn () => $checks(MockDataProfile::Small));

it('recommends the imbalance scenario exactly as the manifest oracle says and keeps the invariants (Medium)', fn () => $checks(MockDataProfile::Medium))->group('slow');
