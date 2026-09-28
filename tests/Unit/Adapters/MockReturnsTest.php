<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\Mock\MockScenarioConfig;
use App\Adapters\MockAdapter;
use App\Core\Analytics\LostSalesCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

/** @return list<Deal> */
function allMockDeals(MockAdapter $adapter): array
{
    return iterator_to_array($adapter->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false);
}

it('gives returns products a sale every month and fully refunds the last month, per the manifest', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();
    $ids = array_keys($manifest->returnProducts);

    expect($ids)->toHaveCount(2)
        ->and(array_intersect($ids, $manifest->lostSalesProductIds, $manifest->seasonalProductIds, array_keys($manifest->deadProducts)))->toBe([])
        ->and($manifest->returnProducts[$ids[0]]['month_net'])->toBe(0.0)
        ->and($manifest->returnProducts[$ids[1]]['month_net'])->toBeLessThan(0.0);

    $sales = [];
    $net = [];
    $refunds = [];
    foreach (allMockDeals($adapter) as $deal) {
        $month = $deal->date->format('Y-m');
        if ($deal->amount > 0) {
            $sales[$deal->productId][$month] = true;
        } else {
            $refunds[] = $deal;
        }
        $net[$deal->productId][$month] = ($net[$deal->productId][$month] ?? 0.0) + $deal->amount;
    }

    $lastMonth = $adapter->historyEnd()->format('Y-m');
    foreach ($manifest->returnProducts as $id => $fact) {
        expect($fact['month'])->toBe($lastMonth)
            ->and($net[$id][$lastMonth])->toEqualWithDelta($fact['month_net'], 0.001);
        for ($month = new DateTimeImmutable($adapter->historyStart()->format('Y-m-01')); $month->format('Y-m') <= $lastMonth; $month = $month->modify('+1 month')) {
            expect($sales[$id] ?? [])->toHaveKey($month->format('Y-m'));
        }
    }

    // Отрицательные сделки — только у товаров сценария и только в последнем месяце.
    expect($refunds)->not->toBeEmpty();
    foreach ($refunds as $refund) {
        expect($ids)->toContain($refund->productId)
            ->and($refund->date->format('Y-m'))->toBe($lastMonth);
    }
});

it('does not change the ordinary deals or other scenarios', function () {
    $without = new MockAdapter(MockDataProfile::Small, 1, new MockScenarioConfig(2, 2, 2, 2, 4, [100, 250], imbalanceCount: 2, lostSalesCount: 2, noSalesDonorCount: 2));
    $with = new MockAdapter(MockDataProfile::Small, 1);
    $returnIds = array_keys($with->manifest()->returnProducts);

    $key = fn (Deal $d) => [$d->id, $d->productId, $d->amount, $d->date->format(DATE_ATOM), $d->sellerId];
    $scenarioDeal = fn (Deal $d) => str_starts_with($d->id, 'deal-return') || str_starts_with($d->id, 'deal-returns-');

    expect(array_map($key, array_values(array_filter(allMockDeals($with), fn (Deal $d) => ! $scenarioDeal($d)))))
        ->toBe(array_map($key, allMockDeals($without)));

    // Движения товаров сценария — как у обычных (сценарий их не трогает).
    $range = new DateRange($with->historyStart(), $with->historyEnd());
    $movements = fn (MockAdapter $a) => array_map(
        fn ($m) => [$m->id, $m->quantity],
        array_values(array_filter(iterator_to_array($a->fetchStockMovements($range), false), fn ($m) => in_array($m->productId, $returnIds, true))),
    );
    expect($movements($with))->toBe($movements($without));
});

it('makes returns products not lost in the fully refunded last month', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $lastMonth = $adapter->historyEnd()->format('Y-m');
    $range = new DateRange(new DateTimeImmutable($lastMonth.'-01')->modify('-2 months'), $adapter->historyEnd());

    $lost = array_map(
        fn ($r) => $r->entityId,
        array_filter((new LostSalesCalculator(1))->calculate(allMockDeals($adapter), $range), fn ($r) => $r->period === 'month:'.$lastMonth),
    );

    expect(array_intersect(array_keys($adapter->manifest()->returnProducts), $lost))->toBe([])
        ->and(array_values(array_intersect($adapter->manifest()->lostSalesProductIds, $lost)))->toBe($adapter->manifest()->lostSalesProductIds);
});
