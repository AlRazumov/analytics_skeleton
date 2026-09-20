<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\Mock\MockScenarioConfig;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;

/** Проверка мёртвых товаров по фактически сгенерированным данным, а не по манифесту. */
function assertDeadProductsMatchManifest(MockAdapter $adapter): void
{
    $manifest = $adapter->manifest();
    $movements = [];
    foreach ($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $adapter->historyEnd())) as $m) {
        if (isset($manifest->deadProducts[$m->productId])) {
            $movements[$m->productId][] = $m;
        }
    }
    $stock = [];
    foreach ($adapter->fetchStock() as $b) {
        if (isset($manifest->deadProducts[$b->productId])) {
            $stock[$b->productId] = ($stock[$b->productId] ?? 0.0) + $b->quantity;
        }
    }

    expect($manifest->deadProducts)->not->toBeEmpty();
    foreach ($manifest->deadProducts as $id => $lastSaleDate) {
        $sales = array_filter($movements[$id], fn ($m) => $m->type === StockMovementType::Sale);
        $lastSale = max(array_map(fn ($m) => $m->date, $sales));
        $lastMovement = max(array_map(fn ($m) => $m->date, $movements[$id]));

        expect($lastSale->format('Y-m-d'))->toBe($lastSaleDate)              // последняя продажа — в заявленную дату
            ->and($lastMovement->format('Y-m-d'))->toBe($lastSaleDate)        // после неё движений нет
            ->and($stock[$id])->toBeGreaterThan(0.0)                          // остаток положительный
            ->and($manifest->deadAges[$id])->toBeGreaterThanOrEqual(90)
            ->and((new DateTimeImmutable($manifest->historyEnd))->diff($lastSale->setTime(0, 0))->days)->toBe($manifest->deadAges[$id])
            ->and(count($sales))->toBeGreaterThan(30);                        // до неё нормальная история продаж
    }
}

it('matches the manifest: last sale on the declared date, nothing after, stock > 0 (Small)', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);

    assertDeadProductsMatchManifest($adapter);
    expect(array_values($adapter->manifest()->deadAges))->toBe([100, 250]);
});

it('matches the manifest: last sale on the declared date, nothing after, stock > 0 (Medium)', function () {
    $adapter = new MockAdapter(MockDataProfile::Medium, 1);

    assertDeadProductsMatchManifest($adapter);
    expect(array_values($adapter->manifest()->deadAges))->toBe([100, 150, 250, 400, 100]);
})->group('slow');

it('spreads dead ages with a different age per product and cycles through the configured list', function () {
    $ages = (new MockAdapter(MockDataProfile::Small, 1))->manifest()->deadAges;

    expect(array_unique($ages))->toHaveCount(2);
});

it('caps an age that does not fit the history and keeps 90 days of ordinary history before the last sale', function () {
    $scenarios = new MockScenarioConfig(2, 2, 2, 2, 4, [900, 120]);
    $adapter = new MockAdapter(MockDataProfile::Small, 1, $scenarios);

    // Small: 365 дней истории → максимум 365 − 1 − 90 = 274 дня.
    expect(array_values($adapter->manifest()->deadAges))->toBe([274, 120]);
    assertDeadProductsMatchManifest($adapter);
});

it('rejects a dead age below the default dead-stock threshold', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1, new MockScenarioConfig(2, 2, 2, 2, 4, [50]));

    expect(fn () => $adapter->manifest())->toThrow(InvalidArgumentException::class);
});

it('does not change any other scenario when only dead ages change', function () {
    $moves = function (MockScenarioConfig $config) {
        $adapter = new MockAdapter(MockDataProfile::Small, 1, $config);
        $skip = $adapter->manifest()->deadProducts;
        $out = [];
        foreach ($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $adapter->historyEnd())) as $m) {
            if (! isset($skip[$m->productId])) {
                $out[] = $m->id.'|'.$m->quantity;
            }
        }

        return md5(implode(',', $out));
    };

    expect($moves(new MockScenarioConfig(2, 2, 2, 2, 4, [100, 250])))->toBe($moves(new MockScenarioConfig(2, 2, 2, 2, 4, [120, 120])));
});
