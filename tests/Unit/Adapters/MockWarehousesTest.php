<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Warehouse;

it('returns deterministic warehouse names', function (MockDataProfile $profile) {
    $a = iterator_to_array((new MockAdapter($profile, 1))->fetchWarehouses(), false);
    $b = iterator_to_array((new MockAdapter($profile, 1))->fetchWarehouses(), false);

    expect($a)->toEqual($b)
        ->and($a)->toHaveCount($profile->warehouseCount())
        ->and($a[0])->toBeInstanceOf(Warehouse::class)
        ->and($a[0]->name)->toBe('Склад 1')
        ->and(array_unique(array_map(fn ($w) => $w->id, $a)))->toHaveCount(count($a))
        ->and(array_unique(array_map(fn ($w) => $w->name, $a)))->toHaveCount(count($a));
})->with([MockDataProfile::Small, MockDataProfile::Medium]);

it('covers every warehouse id used by movements and balances', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();
    $known = array_map(fn ($w) => $w->id, iterator_to_array($adapter->fetchWarehouses(), false));

    $used = [];
    foreach ($adapter->fetchStockMovements(new DateRange(new DateTimeImmutable($manifest->historyStart), new DateTimeImmutable($manifest->historyEnd))) as $m) {
        $used[$m->warehouseId] = true;
    }
    foreach ([null, new DateTimeImmutable($manifest->historyStart), new DateTimeImmutable($manifest->historyEnd)] as $asOf) {
        foreach ($adapter->fetchStock($asOf) as $b) {
            $used[$b->warehouseId] = true;
        }
    }

    expect($used)->not->toBeEmpty()
        ->and(array_diff(array_keys($used), $known))->toBe([]);
});
