<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

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

it('keeps memory growth sub-linear for the medium profile stock movement stream', function () {
    $adapter = new MockAdapter(MockDataProfile::Medium, 1);

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
    $few = new MockAdapter(MockDataProfile::Medium, 1);
    $start = microtime(true);
    $seen = 0;
    foreach ($few->fetchStockMovements(mockPeriod()) as $movement) {
        $seen++;
        if ($seen >= 10) {
            break;
        }
    }
    $fewDuration = microtime(true) - $start;

    $all = new MockAdapter(MockDataProfile::Medium, 1);
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

it('generates the profile-configured number of products', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);

    $products = iterator_to_array($adapter->fetchProducts());

    expect($products)->toHaveCount(MockDataProfile::Small->productCount());
});

/** Вся история профиля: [движения, адаптер]. Кэшируется — генерация не дешёвая. */
function mockWorld(MockDataProfile $profile = MockDataProfile::Small, int $seed = 1): array
{
    static $cache = [];
    $key = $profile->value.':'.$seed;

    if (! isset($cache[$key])) {
        $adapter = new MockAdapter($profile, $seed);
        $manifest = $adapter->manifest();
        $movements = iterator_to_array($adapter->fetchStockMovements(
            new DateRange(new DateTimeImmutable($manifest->historyStart), new DateTimeImmutable($manifest->historyEnd))
        ), false);
        $cache[$key] = [$movements, $adapter];
    }

    return $cache[$key];
}

/** Остатки на конец дня по сумме движений: "productId|warehouseId" => qty. */
function balancesFromMovements(array $movements, string $ymd): array
{
    $cutoff = (new DateTimeImmutable($ymd))->modify('+1 day');
    $balances = [];
    foreach ($movements as $m) {
        if ($m->date < $cutoff) {
            $key = $m->productId.'|'.$m->warehouseId;
            $balances[$key] = ($balances[$key] ?? 0.0) + $m->quantity;
        }
    }

    return $balances;
}

function balancesFromFetchStock(MockAdapter $adapter, ?string $ymd): array
{
    $balances = [];
    foreach ($adapter->fetchStock($ymd === null ? null : new DateTimeImmutable($ymd)) as $b) {
        $balances[$b->productId.'|'.$b->warehouseId] = $b->quantity;
    }

    return $balances;
}

function endOfDayStock(array $movements, string $productId, string $warehouseId, string $ymd): float
{
    return balancesFromMovements(array_filter($movements, fn (StockMovement $m) => $m->productId === $productId && $m->warehouseId === $warehouseId), $ymd)["$productId|$warehouseId"] ?? 0.0;
}

it('declares both stock capabilities', function () {
    expect((new MockAdapter(MockDataProfile::Small))->capabilities())
        ->toBe([AdapterCapability::StockMovements, AdapterCapability::StockSnapshots]);
});

it('generates identical stock history and balances for the same seed, different for another', function () {
    [$first] = mockWorld(MockDataProfile::Small, 1);
    $range = new DateRange(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2026-08-31'));
    $digest = fn (iterable $items) => md5(serialize(iterator_to_array($items, false)));

    $again = new MockAdapter(MockDataProfile::Small, 1);
    $other = new MockAdapter(MockDataProfile::Small, 2);

    expect($digest($again->fetchStockMovements($range)))->toBe(md5(serialize($first)))
        ->and($digest($again->fetchStock()))->toBe($digest((new MockAdapter(MockDataProfile::Small, 1))->fetchStock()))
        ->and($digest($other->fetchStockMovements($range)))->not->toBe(md5(serialize($first)))
        ->and($digest($other->fetchStock()))->not->toBe($digest($again->fetchStock()));
});

it('has balance equal to the sum of movements up to asOf (end of that day)', function (?string $asOf) {
    [$movements, $adapter] = mockWorld();
    $expected = balancesFromMovements($movements, $asOf ?? '2026-08-31');
    $actual = balancesFromFetchStock($adapter, $asOf);

    foreach ($actual as $key => $quantity) {
        expect($quantity)->toEqualWithDelta($expected[$key] ?? 0.0, 1e-9);
    }
    expect(array_diff_key($expected, $actual))->toBe([]);
})->with([null, '2026-08-31', '2026-03-15', '2025-09-01', '2025-08-31', '2030-01-01']);

it('never lets a stock balance go negative at any point in time', function (MockDataProfile $profile) {
    [$movements] = mockWorld($profile);
    usort($movements, fn ($x, $y) => $x->date <=> $y->date);

    $balances = [];
    $lowest = 0.0;
    foreach ($movements as $m) {
        $key = $m->productId.'|'.$m->warehouseId;
        $balances[$key] = ($balances[$key] ?? 0.0) + $m->quantity;
        $lowest = min($lowest, $balances[$key]);
    }

    expect($movements)->not->toBeEmpty()
        ->and($lowest)->toBe(0.0);
})->with([MockDataProfile::Small, MockDataProfile::Medium]);

it('emits transfers as paired transfer_out/transfer_in of equal quantity', function () {
    [$movements] = mockWorld(MockDataProfile::Medium);

    $byTransfer = [];
    foreach ($movements as $m) {
        if (in_array($m->type, [StockMovementType::TransferIn, StockMovementType::TransferOut], true)) {
            expect($m->meta)->toHaveKey('transfer_id');
            $byTransfer[$m->meta['transfer_id']][] = $m;
        }
    }

    expect($byTransfer)->not->toBeEmpty();
    foreach ($byTransfer as $pair) {
        expect($pair)->toHaveCount(2);
        [$out, $in] = $pair[0]->type === StockMovementType::TransferOut ? $pair : [$pair[1], $pair[0]];
        expect($out->type)->toBe(StockMovementType::TransferOut)
            ->and($in->type)->toBe(StockMovementType::TransferIn)
            ->and($out->quantity)->toBe(-$in->quantity)
            ->and($out->productId)->toBe($in->productId)
            ->and($out->date)->toEqual($in->date)
            ->and($out->warehouseId)->not->toBe($in->warehouseId);
    }
});

it('produces every movement type', function () {
    [$movements] = mockWorld(MockDataProfile::Medium);

    $types = array_unique(array_map(fn ($m) => $m->type, $movements), SORT_REGULAR);

    expect($types)->toHaveCount(count(StockMovementType::cases()));
});

it('returns the same slice of history regardless of the requested range', function () {
    [$movements, $adapter] = mockWorld();
    $range = new DateRange(new DateTimeImmutable('2026-02-10'), new DateTimeImmutable('2026-02-20'));

    $expected = array_values(array_filter(
        $movements,
        fn ($m) => $m->date >= new DateTimeImmutable('2026-02-10') && $m->date < new DateTimeImmutable('2026-02-21'),
    ));

    expect(iterator_to_array($adapter->fetchStockMovements($range), false))->toEqual($expected)
        ->and($expected)->not->toBeEmpty();
});

it('has no movements outside the history window', function () {
    [, $adapter] = mockWorld();

    $before = new DateRange(new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2020-12-31'));

    expect(iterator_to_array($adapter->fetchStockMovements($before), false))->toBe([]);
});

it('places scenario products according to the configured counts', function (MockDataProfile $profile) {
    $config = $profile->scenarios();
    $manifest = (new MockAdapter($profile))->manifest();

    expect($manifest->deadProducts)->toHaveCount($config->deadCount)
        ->and($manifest->nearZeroProducts)->toHaveCount($config->nearZeroCount)
        ->and($manifest->gapProducts)->toHaveCount($config->gapCount)
        ->and($manifest->spikeProducts)->toHaveCount($config->spikeCount)
        ->and($manifest->seasonalProductIds)->toHaveCount($config->seasonalCount)
        ->and($manifest->hasTransfers)->toBeTrue()
        ->and($manifest->deadDays)->toBeGreaterThan(90);
})->with(MockDataProfile::cases());

it('has a yearly sales wave for seasonal products (scenario 1)', function () {
    [$movements, $adapter] = mockWorld();
    $seasonal = array_flip($adapter->manifest()->seasonalProductIds);

    $sold = fn (string $month) => -array_sum(array_map(
        fn ($m) => $m->type === StockMovementType::Sale && isset($seasonal[$m->productId]) && $m->date->format('Y-m') === $month ? $m->quantity : 0,
        $movements,
    ));

    expect($sold('2025-12'))->toBeGreaterThan(2 * $sold('2026-06'));
});

it('has dead products with stock but no movements for the last N days (scenario 2)', function () {
    [$movements, $adapter] = mockWorld();
    $manifest = $adapter->manifest();
    $stock = balancesFromFetchStock($adapter, null);

    foreach ($manifest->deadProducts as $productId => $lastMovement) {
        $own = array_filter($movements, fn ($m) => $m->productId === $productId);
        $last = max(array_map(fn ($m) => $m->date, $own));
        $daysIdle = (new DateTimeImmutable($manifest->historyEnd))->diff(new DateTimeImmutable($last->format('Y-m-d')))->days;

        expect($last->format('Y-m-d'))->toBe($lastMovement)
            ->and($daysIdle)->toBeGreaterThanOrEqual($manifest->deadDays)
            ->and($daysIdle)->toBeGreaterThan(90)
            ->and(array_sum(array_filter($stock, fn ($k) => str_starts_with($k, $productId.'|'), ARRAY_FILTER_USE_KEY)))->toBeGreaterThan(0.0);
    }

    // Обычные товары в те же дни двигаются.
    $regular = array_filter($movements, fn ($m) => ! isset($manifest->deadProducts[$m->productId]) && $m->date >= new DateTimeImmutable($manifest->historyEnd.' -30 days'));
    expect($regular)->not->toBeEmpty();
});

it('has near-zero products with stable sales and a few days of stock (scenario 3)', function () {
    [$movements, $adapter] = mockWorld();
    $manifest = $adapter->manifest();
    $end = $manifest->historyEnd;

    foreach ($manifest->nearZeroProducts as $productId => $fact) {
        $lastWeekSales = array_filter($movements, fn ($m) => $m->productId === $productId
            && $m->type === StockMovementType::Sale && $m->date >= new DateTimeImmutable($end.' -6 days'));

        expect(endOfDayStock($movements, $productId, $fact['warehouse_id'], $end))->toBe((float) $fact['stock_at_end'])
            ->and($fact['stock_at_end'])->toBe($fact['daily_rate'] * $fact['days_to_zero'])
            ->and($fact['days_to_zero'])->toBeLessThan(10)
            ->and($lastWeekSales)->toHaveCount(7);
        foreach ($lastWeekSales as $sale) {
            expect($sale->quantity)->toBe((float) -$fact['daily_rate']);
        }
    }
});

it('has stock-out gaps with no sales, sales before and after (scenario 4)', function () {
    [$movements, $adapter] = mockWorld();

    foreach ($adapter->manifest()->gapProducts as $productId => $windows) {
        $own = array_values(array_filter($movements, fn ($m) => $m->productId === $productId));
        $salesOn = fn (string $ymd) => array_filter($own, fn ($m) => $m->type === StockMovementType::Sale && $m->date->format('Y-m-d') === $ymd);
        $warehouse = $own[0]->warehouseId;

        expect($windows)->toHaveCount(3);
        foreach ($windows as $window) {
            for ($day = new DateTimeImmutable($window['from']); $day <= new DateTimeImmutable($window['to']); $day = $day->modify('+1 day')) {
                expect(endOfDayStock($own, $productId, $warehouse, $day->format('Y-m-d')))->toBe(0.0)
                    ->and(array_filter($own, fn ($m) => $m->date->format('Y-m-d') === $day->format('Y-m-d')))->toBe([]);
            }
            expect($salesOn((new DateTimeImmutable($window['from']))->modify('-1 day')->format('Y-m-d')))->not->toBeEmpty()
                ->and($salesOn((new DateTimeImmutable($window['to']))->modify('+1 day')->format('Y-m-d')))->not->toBeEmpty();
        }
    }
});

it('has a one-off sales spike (scenario 5)', function () {
    [$movements, $adapter] = mockWorld();

    foreach ($adapter->manifest()->spikeProducts as $productId => $fact) {
        $soldOn = function (string $ymd) use ($movements, $productId) {
            return -array_sum(array_map(
                fn ($m) => $m->productId === $productId && $m->type === StockMovementType::Sale && $m->date->format('Y-m-d') === $ymd ? $m->quantity : 0,
                $movements,
            ));
        };

        expect($soldOn($fact['from']))->toBe((float) ($fact['baseline_daily'] * $fact['multiplier']))
            ->and($soldOn($fact['to']))->toBe((float) ($fact['baseline_daily'] * $fact['multiplier']))
            ->and($soldOn((new DateTimeImmutable($fact['from']))->modify('-1 day')->format('Y-m-d')))->toBe((float) $fact['baseline_daily'])
            ->and($soldOn((new DateTimeImmutable($fact['to']))->modify('+1 day')->format('Y-m-d')))->toBe((float) $fact['baseline_daily']);
    }
});

it('has several warehouses in every profile', function (MockDataProfile $profile) {
    expect(count($profile->warehouseIds()))->toBeGreaterThanOrEqual(2);
})->with(MockDataProfile::cases());

it('gives every movement a unique id', function () {
    [$movements] = mockWorld(MockDataProfile::Medium);

    expect(count(array_unique(array_map(fn ($m) => $m->id, $movements))))->toBe(count($movements));
});

it('sells about multiplier × baseline on EVERY day of the spike window, not only at its edges (scenario 5)', function () {
    [$movements, $adapter] = mockWorld();

    foreach ($adapter->manifest()->spikeProducts as $productId => $fact) {
        $dailySales = [];
        foreach ($movements as $m) {
            if ($m->productId === $productId && $m->type === StockMovementType::Sale) {
                $day = $m->date->format('Y-m-d');
                $dailySales[$day] = ($dailySales[$day] ?? 0.0) - $m->quantity;
            }
        }

        $window = [];
        for ($day = new DateTimeImmutable($fact['from']); $day <= new DateTimeImmutable($fact['to']); $day = $day->modify('+1 day')) {
            $window[] = $dailySales[$day->format('Y-m-d')] ?? 0.0;
        }
        $outside = array_diff_key($dailySales, array_flip(array_map(fn ($i) => (new DateTimeImmutable($fact['from']))->modify("+$i days")->format('Y-m-d'), array_keys($window))));

        $expected = $fact['baseline_daily'] * $fact['multiplier'];
        foreach ($window as $sold) {
            expect($sold)->toBeGreaterThanOrEqual($expected * 0.9)->toBeLessThanOrEqual($expected * 1.1);
        }
        expect(array_sum($window) / count($window))->toBeGreaterThan(0.9 * $expected)
            ->and(max($outside))->toBeLessThanOrEqual($fact['baseline_daily'] * 1.1)
            ->and(array_sum($window) / count($window))->toBeGreaterThan(5 * (array_sum($outside) / count($outside)));
    }
});
