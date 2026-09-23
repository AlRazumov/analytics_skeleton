<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;

it('gives lost-sales products a deal in every month except the last, and no seasonal overlap', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();

    expect($manifest->lostSalesProductIds)->not->toBeEmpty()
        ->and(array_intersect($manifest->lostSalesProductIds, $manifest->seasonalProductIds))->toBe([]);

    $byMonth = [];
    foreach ($adapter->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())) as $deal) {
        if (in_array($deal->productId, $manifest->lostSalesProductIds, true)) {
            $byMonth[$deal->productId][$deal->date->format('Y-m')] = true;
        }
    }

    $lastMonth = $adapter->historyEnd()->format('Y-m');
    $firstMonth = $adapter->historyStart()->format('Y-m');

    foreach ($manifest->lostSalesProductIds as $id) {
        expect($byMonth[$id] ?? [])->not->toHaveKey($lastMonth);
        // Каждый месяц окна истории, кроме последнего, содержит сделку.
        for ($month = new DateTimeImmutable($firstMonth.'-01'); $month->format('Y-m') !== $lastMonth; $month = $month->modify('+1 month')) {
            expect($byMonth[$id] ?? [])->toHaveKey($month->format('Y-m'));
        }
    }
});

it('is deterministic and independent of the requested query range', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 7);
    $manifest = $adapter->manifest();
    $lastMonth = $adapter->historyEnd()->format('Y-m');

    $wide = iterator_to_array($adapter->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false);
    $narrow = iterator_to_array($adapter->fetchDeals(new DateRange(
        new DateTimeImmutable($lastMonth.'-01'),
        $adapter->historyEnd(),
    )), false);

    $lastMonthOf = fn (array $deals) => array_values(array_filter(
        $deals, fn ($d) => $d->date->format('Y-m') === $lastMonth && in_array($d->productId, $manifest->lostSalesProductIds, true),
    ));

    expect($lastMonthOf($wide))->toBe([])->and($lastMonthOf($narrow))->toBe([]);
});
