<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\Mock\MockScenarioConfig;
use App\Adapters\MockAdapter;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;

const IMBALANCE_KEYS = [
    'surplus_warehouse_id', 'deficit_warehouse_id', 'surplus_stock_at_end', 'surplus_daily_rate',
    'deficit_stock_at_end', 'deficit_daily_rate', 'surplus_days_of_stock', 'deficit_days_of_stock',
];

/** Проверка сценария по фактически сгенерированным данным; манифест — только сверяемое. */
function assertImbalanceMatchesData(MockAdapter $adapter): void
{
    $manifest = $adapter->manifest();
    $end = $adapter->historyEnd();
    $windowStart = $end->modify('-27 days')->setTime(0, 0);

    $stock = [];
    foreach ($adapter->fetchStock() as $b) {
        $stock[$b->productId][$b->warehouseId] = $b->quantity;
    }

    $movements = [];
    foreach ($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $end)) as $m) {
        if (isset($manifest->imbalanceProducts[$m->productId])) {
            $movements[$m->productId][] = $m;
        }
    }

    expect($manifest->imbalanceProducts)->not->toBeEmpty();
    foreach ($manifest->imbalanceProducts as $id => $fact) {
        expect(array_keys($fact))->toBe(IMBALANCE_KEYS);
        $surplus = $fact['surplus_warehouse_id'];
        $deficit = $fact['deficit_warehouse_id'];
        expect($surplus)->not->toBe($deficit);

        // Остатки на historyEnd (fetchStock) совпадают с манифестом; на остальных складах пусто.
        expect($stock[$id][$surplus])->toBe((float) $fact['surplus_stock_at_end'])
            ->and($stock[$id][$deficit])->toBe((float) $fact['deficit_stock_at_end']);
        foreach ($stock[$id] as $warehouse => $quantity) {
            if (! in_array($warehouse, [$surplus, $deficit], true)) {
                expect($quantity)->toBe(0.0);
            }
        }

        // Скорость продаж — по sale-движениям последних 28 дней окна; перемещений и прочих типов кроме приёмки/продажи нет.
        $sold = [$surplus => 0.0, $deficit => 0.0];
        $balance = [$surplus => 0.0, $deficit => 0.0];
        $minInWindow = [$surplus => INF, $deficit => INF];
        $lowest = 0.0;
        foreach ($movements[$id] as $m) {
            expect($m->type)->toBeIn([StockMovementType::Receipt, StockMovementType::Sale])
                ->and($m->warehouseId)->toBeIn([$surplus, $deficit]);
            $balance[$m->warehouseId] += $m->quantity;
            $lowest = min($lowest, $balance[$m->warehouseId]);
            if ($m->date >= $windowStart) {
                $minInWindow[$m->warehouseId] = min($minInWindow[$m->warehouseId], $balance[$m->warehouseId]);
                if ($m->type === StockMovementType::Sale) {
                    $sold[$m->warehouseId] -= $m->quantity;
                }
            }
        }
        $rateSurplus = $sold[$surplus] / 28;
        $rateDeficit = $sold[$deficit] / 28;

        expect($rateSurplus)->toEqualWithDelta((float) $fact['surplus_daily_rate'], 1e-9)
            ->and($rateDeficit)->toEqualWithDelta((float) $fact['deficit_daily_rate'], 1e-9)
            ->and($stock[$id][$surplus] / $rateSurplus)->toEqualWithDelta((float) $fact['surplus_days_of_stock'], 1e-9)
            ->and($stock[$id][$deficit] / $rateDeficit)->toEqualWithDelta((float) $fact['deficit_days_of_stock'], 1e-9)
            // Пороги покрытия.
            ->and($fact['surplus_days_of_stock'])->toBeGreaterThanOrEqual(90)
            ->and($fact['deficit_days_of_stock'])->toBeLessThanOrEqual(10)->toBeGreaterThan(0)
            ->and($rateSurplus)->toBeLessThan($rateDeficit)
            // Остатки неотрицательны всегда; дефицит не обнуляется в окне метрики.
            ->and($lowest)->toBe(0.0)
            ->and($minInWindow[$deficit])->toBeGreaterThan(0.0)
            ->and($minInWindow[$surplus])->toBeGreaterThan(0.0);
    }
}

it('places imbalance products per the config and keeps them apart from the other scenarios', function (MockDataProfile $profile) {
    $manifest = (new MockAdapter($profile))->manifest();
    $all = [
        ...array_keys($manifest->deadProducts), ...array_keys($manifest->nearZeroProducts),
        ...array_keys($manifest->gapProducts), ...array_keys($manifest->spikeProducts),
        ...$manifest->seasonalProductIds, ...array_keys($manifest->imbalanceProducts),
    ];

    expect($manifest->imbalanceProducts)->toHaveCount($profile->scenarios()->imbalanceCount)
        ->and(array_unique($all))->toHaveCount(count($all));
})->with(MockDataProfile::cases());

it('has the configured imbalance counts: Small 2, Medium 5, Large 10', function () {
    expect(MockDataProfile::Small->scenarios()->imbalanceCount)->toBe(2)
        ->and(MockDataProfile::Medium->scenarios()->imbalanceCount)->toBe(5)
        ->and(MockDataProfile::Large->scenarios()->imbalanceCount)->toBe(10);
});

it('generates a surplus and a deficit warehouse that match the manifest (Small)', function () {
    assertImbalanceMatchesData(new MockAdapter(MockDataProfile::Small, 1));
});

it('generates a surplus and a deficit warehouse that match the manifest (Medium)', function () {
    assertImbalanceMatchesData(new MockAdapter(MockDataProfile::Medium, 1));
})->group('slow');

it('is seen by the days-of-stock metric with the manifest coverage', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();
    $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable($manifest->historyEnd));

    $records = collect((new DaysOfStockCalculator(28, 7))->calculate($adapter, $range))->keyBy('entityId');

    foreach ($manifest->imbalanceProducts as $id => $fact) {
        expect($records["{$id}:{$fact['surplus_warehouse_id']}"]->value)->toEqualWithDelta($fact['surplus_days_of_stock'], 1e-9)
            ->and($records["{$id}:{$fact['deficit_warehouse_id']}"]->value)->toEqualWithDelta($fact['deficit_days_of_stock'], 1e-9);
    }
});

it('is independent of the requested range and of the seed', function () {
    $ids = fn (MockAdapter $a, DateRange $r) => array_map(
        fn ($m) => $m->id.'|'.$m->quantity,
        array_values(array_filter(iterator_to_array($a->fetchStockMovements($r), false), fn ($m) => isset($a->manifest()->imbalanceProducts[$m->productId]))),
    );
    $a = new MockAdapter(MockDataProfile::Small, 1);
    $whole = $ids($a, new DateRange($a->historyStart(), $a->historyEnd()));
    $part = $ids($a, new DateRange(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31')));

    expect($part)->not->toBeEmpty()->and(array_diff($part, $whole))->toBe([])
        ->and($ids(new MockAdapter(MockDataProfile::Small, 99), new DateRange($a->historyStart(), $a->historyEnd())))->toBe($whole);
});

it('leaves every other product exactly as without the scenario', function () {
    $movements = function (MockScenarioConfig $config) {
        $digest = fn (array $lines): string => md5(implode(',', $lines));
        $adapter = new MockAdapter(MockDataProfile::Small, 1, $config);
        $imbalance = $adapter->manifest()->imbalanceProducts;
        $byProduct = [];
        foreach ($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $adapter->historyEnd())) as $m) {
            if (! isset($imbalance[$m->productId])) {
                $byProduct[$m->productId][] = $m->id.'|'.$m->warehouseId.'|'.$m->quantity.'|'.$m->type->value.'|'.$m->date->format('c');
            }
        }

        return [$adapter->manifest(), array_map($digest, $byProduct)];
    };

    $base = MockDataProfile::Small->scenarios();
    $without = new MockScenarioConfig($base->deadCount, $base->nearZeroCount, $base->gapCount, $base->spikeCount, $base->seasonalCount, $base->deadAges, 0);
    [, $with] = $movements($base);
    [, $plain] = $movements($without);

    // Каждый товар, не отданный под дисбаланс, — те же движения; товары сценария в «без сценария» — обычные.
    expect(array_diff_key($plain, $with))->toHaveCount($base->imbalanceCount);
    foreach ($with as $productId => $hash) {
        expect($plain[$productId])->toBe($hash);
    }
});
