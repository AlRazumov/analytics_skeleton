<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;

function mockPeriod(): DateRange
{
    return new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));
}

function takeFirst(iterable $items, int $n): Generator
{
    $i = 0;
    foreach ($items as $item) {
        if ($i++ >= $n) {
            break;
        }
        yield $item;
    }
}

it('returns a Generator from every fetch method', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);

    expect($adapter->fetchProducts())->toBeInstanceOf(Generator::class)
        ->and($adapter->fetchDeals(mockPeriod()))->toBeInstanceOf(Generator::class)
        ->and($adapter->fetchStockMovements(mockPeriod()))->toBeInstanceOf(Generator::class);
});

it('is reproducible for the same profile and seed', function () {
    $a = new MockAdapter(MockDataProfile::Medium, 7);
    $b = new MockAdapter(MockDataProfile::Medium, 7);

    $deals1 = iterator_to_array(takeFirst($a->fetchDeals(mockPeriod()), 20));
    $deals2 = iterator_to_array(takeFirst($b->fetchDeals(mockPeriod()), 20));

    $stock1 = iterator_to_array(takeFirst($a->fetchStockMovements(mockPeriod()), 20));
    $stock2 = iterator_to_array(takeFirst($b->fetchStockMovements(mockPeriod()), 20));

    expect($deals1)->toEqual($deals2)
        ->and($stock1)->toEqual($stock2);
});

it('produces different results for different seeds', function () {
    $a = new MockAdapter(MockDataProfile::Medium, 7);
    $b = new MockAdapter(MockDataProfile::Medium, 8);

    $deals1 = iterator_to_array(takeFirst($a->fetchDeals(mockPeriod()), 20));
    $deals2 = iterator_to_array(takeFirst($b->fetchDeals(mockPeriod()), 20));

    expect($deals1)->not->toEqual($deals2);
});

it('keeps memory growth sub-linear for the large profile stock movement stream', function () {
    $adapter = new MockAdapter(MockDataProfile::Large, 1);

    $count = 0;
    $memoryAfterFew = null;
    foreach ($adapter->fetchStockMovements(mockPeriod()) as $movement) {
        $count++;
        if ($count === 10) {
            $memoryAfterFew = memory_get_usage();
        }
    }
    $memoryAfterAll = memory_get_usage();

    expect($count)->toBeGreaterThan(1000)
        ->and($memoryAfterFew)->not->toBeNull();
    // Не пропорционально числу записей: полный проход по десяткам
    // тысяч записей не должен на порядки превышать память после
    // первых 10 (некоторый рост ожидаем и допустим).
    expect($memoryAfterAll)->toBeLessThan($memoryAfterFew * 20);
});

it('stops generating work early when the caller breaks out of the loop', function () {
    $few = new MockAdapter(MockDataProfile::Large, 1);
    $start = microtime(true);
    $seen = 0;
    foreach ($few->fetchStockMovements(mockPeriod()) as $movement) {
        $seen++;
        if ($seen >= 10) {
            break;
        }
    }
    $fewDuration = microtime(true) - $start;

    $all = new MockAdapter(MockDataProfile::Large, 1);
    $start = microtime(true);
    $total = 0;
    foreach ($all->fetchStockMovements(mockPeriod()) as $movement) {
        $total++;
    }
    $allDuration = microtime(true) - $start;

    expect($seen)->toBe(10)
        ->and($total)->toBeGreaterThan(10)
        ->and($fewDuration)->toBeLessThan($allDuration);
});

it('includes a warehouse imbalance scenario for the large profile', function () {
    $adapter = new MockAdapter(MockDataProfile::Large, 3);
    $warehouses = MockDataProfile::Large->warehouseIds();

    $net = [];
    foreach ($adapter->fetchStockMovements(mockPeriod()) as $movement) {
        if ($movement->productId !== 'prod-1' || $movement->type === StockMovementType::Transfer) {
            continue;
        }

        $delta = $movement->type === StockMovementType::In ? $movement->quantity : -$movement->quantity;
        $net[$movement->warehouseId] = ($net[$movement->warehouseId] ?? 0) + $delta;
    }

    expect($net[$warehouses[0]] ?? 0)->toBeGreaterThan(1000)
        ->and($net[$warehouses[1]] ?? 0)->toBeLessThan(-1000);
});

it('does not include the imbalance scenario for the small profile', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 3);

    foreach ($adapter->fetchStockMovements(mockPeriod()) as $movement) {
        expect($movement->id)->not->toStartWith('stock-imbalance-');
    }
});

it('respects the Transfer/toWarehouseId invariant from Stage 01 across the stream', function () {
    $adapter = new MockAdapter(MockDataProfile::Medium, 5);

    $sawTransfer = false;
    foreach (takeFirst($adapter->fetchStockMovements(mockPeriod()), 5000) as $movement) {
        if ($movement->type === StockMovementType::Transfer) {
            $sawTransfer = true;
            expect($movement->toWarehouseId)->not->toBeNull()
                ->and($movement->toWarehouseId)->not->toBe($movement->warehouseId);
        } else {
            expect($movement->toWarehouseId)->toBeNull();
        }
    }

    expect($sawTransfer)->toBeTrue();
});

it('does not generate transfers for the single-warehouse small profile', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 5);

    foreach ($adapter->fetchStockMovements(mockPeriod()) as $movement) {
        expect($movement->type)->not->toBe(StockMovementType::Transfer);
    }
});

it('generates the profile-configured number of products', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);

    $products = iterator_to_array($adapter->fetchProducts());

    expect($products)->toHaveCount(MockDataProfile::Small->productCount());
});
