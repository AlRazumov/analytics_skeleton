<?php

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType as T;

/** Окно 28 дней, заканчивающееся 2026-03-31: 2026-03-04 .. 2026-03-31. */
function daysOfStock(array $movements, array $opening, ?DaysOfStockCalculator $calc = null): array
{
    $calc ??= new DaysOfStockCalculator(28, 7);
    $range = new DateRange(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));

    $records = collect($calc->calculate(stubStockAdapter($movements, $opening), $range))->keyBy('entityId')->all();

    return [$records, $calc];
}

/** По одной продаже в день с 4 по 31 марта. */
function dailySales(string $product, string $warehouse, float $perDay, string $from = '2026-03-04', string $to = '2026-03-31'): array
{
    $moves = [];
    for ($d = new DateTimeImmutable($from); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) {
        $moves[] = stockMove($d->format('Y-m-d'), $product, $warehouse, T::Sale, -$perDay);
    }

    return $moves;
}

it('divides the stock on asOf by the average daily demand', function () {
    // Остаток 100 до окна, 2 шт./день × 28 дней → на конец 44, скорость 2 → 22 дня.
    [$records] = daysOfStock(dailySales('p1', 'w1', 2), ['p1|w1' => 100.0]);

    expect($records['p1:w1']->value)->toBe(22.0)
        ->and($records['p1:w1']->period)->toBe('month:2026-03')
        ->and($records['p1:w1']->entityType)->toBe('product_warehouse')
        ->and($records['p1:w1']->valueMeta)->toBe(['stock_qty' => 44.0, 'daily_rate' => 2.0, 'in_stock_days' => 28, 'window_days' => 28]);
});

it('gives 0 when the stock on asOf is zero and there was demand', function () {
    [$records] = daysOfStock(dailySales('p1', 'w1', 2), ['p1|w1' => 56.0]);

    expect($records['p1:w1']->value)->toBe(0.0)->and($records['p1:w1']->valueMeta['stock_qty'])->toBe(0.0);
});

it('writes nothing to days_of_stock when there were no sales in the window, but counts it and writes stock_no_demand', function () {
    [$records, $calc] = daysOfStock([stockMove('2026-03-10', 'p1', 'w1', T::Receipt, 5)], ['p1|w1' => 10.0]);

    expect($records)->toHaveCount(1)
        ->and($records['p1:w1']->metricKey)->toBe(DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY)
        ->and($records['p1:w1']->value)->toBe(15.0)
        ->and($records['p1:w1']->valueMeta)->toBe(['stock_qty' => 15.0])
        ->and($calc->lastSkipped['no_demand'])->toBe(1);
});

it('does not count sales outside the window as demand, and still writes stock_no_demand for the remaining stock', function () {
    [$records] = daysOfStock([stockMove('2026-03-03', 'p1', 'w1', T::Sale, -5)], ['p1|w1' => 50.0]);

    expect($records)->toHaveCount(1)
        ->and($records['p1:w1']->metricKey)->toBe(DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY)
        ->and($records['p1:w1']->value)->toBe(45.0);
});

it('does not write stock_no_demand when the remaining stock is zero', function () {
    [$records, $calc] = daysOfStock([stockMove('2026-03-10', 'p1', 'w1', T::Writeoff, -10)], ['p1|w1' => 10.0]);

    expect($records)->toBe([])->and($calc->lastSkipped['no_demand'])->toBe(1);
});

it('does not treat transfers, receipts, writeoffs and adjustments as demand, but applies them to the stock', function () {
    [$records] = daysOfStock([
        stockMove('2026-03-05', 'p1', 'w1', T::Sale, -10),
        stockMove('2026-03-06', 'p1', 'w1', T::TransferOut, -20),
        stockMove('2026-03-06', 'p1', 'w2', T::TransferIn, 20),
        stockMove('2026-03-07', 'p1', 'w1', T::Writeoff, -5),
        stockMove('2026-03-08', 'p1', 'w1', T::Adjustment, 3),
        stockMove('2026-03-09', 'p1', 'w1', T::Receipt, 12),
    ], ['p1|w1' => 100.0]);

    // Остаток на конец: 100 − 10 − 20 − 5 + 3 + 12 = 80; спрос 10 / 28 дней.
    expect($records['p1:w1']->valueMeta)->toMatchArray(['stock_qty' => 80.0, 'in_stock_days' => 28])
        ->and($records['p1:w1']->value)->toBe(80.0 / (10 / 28))
        ->and($records)->toHaveCount(1); // w2 продаж не имела
});

it('excludes zero-stock days from the denominator', function () {
    // 10 дней подряд остатка нет (с 8 по 17 марта включительно), потом приход
    // 18-го утром; в остальные дни по 2 шт. Остаток на начало 4 марта: 8.
    $moves = [
        stockMove('2026-03-04', 'p1', 'w1', T::Sale, -2),
        stockMove('2026-03-05', 'p1', 'w1', T::Sale, -2),
        stockMove('2026-03-06', 'p1', 'w1', T::Sale, -2),
        stockMove('2026-03-07', 'p1', 'w1', T::Sale, -2),
        stockMove('2026-03-18', 'p1', 'w1', T::Receipt, 100),
        ...dailySales('p1', 'w1', 2, '2026-03-19', '2026-03-31'),
    ];
    [$records] = daysOfStock($moves, ['p1|w1' => 8.0]);

    // Дни с остатком на начало дня > 0: 4–7 марта (4) + 19–31 марта (13); 8–18 марта — ноль.
    // Продажи: 4×2 + 13×2 = 34 → скорость 2.
    expect($records['p1:w1']->valueMeta)->toMatchArray(['in_stock_days' => 17, 'daily_rate' => 2.0])
        ->and($records['p1:w1']->value)->toBe((100 - 26) / 2.0);

    // «Наивно» по 28 календарным дням скорость была бы 34/28 ≈ 1.21 (занижена).
    expect(34 / 28)->toBeLessThan($records['p1:w1']->valueMeta['daily_rate']);
});

it('does not count sales of the arrival day (start-of-day stock 0) in the numerator', function () {
    // Остатка нет до 20 марта; 20-го утром приход 100 и в тот же день продано 50 —
    // это день с остатком на начало 0, его продажи в числитель не входят.
    // Дальше 21–31 марта (11 дней) по 2 шт./день.
    $moves = [
        stockMove('2026-03-20', 'p1', 'w1', T::Receipt, 100),
        stockMove('2026-03-20', 'p1', 'w1', T::Sale, -50),
        ...dailySales('p1', 'w1', 2, '2026-03-21', '2026-03-31'),
    ];
    [$records] = daysOfStock($moves, []);

    // Дней в наличии 11, продаж в них 22 → скорость 2 (а не 72/11 ≈ 6.5); остаток 100 − 50 − 22 = 28.
    expect($records['p1:w1']->valueMeta)->toMatchArray(['in_stock_days' => 11, 'daily_rate' => 2.0, 'stock_qty' => 28.0])
        ->and($records['p1:w1']->value)->toBe(14.0);
});

it('writes nothing when the only sales fell on a day with zero start-of-day stock', function () {
    // Приход и продажа 20 марта (остаток на начало дня 0); дальше 11 дней в наличии без продаж.
    [$records, $calc] = daysOfStock([
        stockMove('2026-03-20', 'p1', 'w1', T::Receipt, 10),
        stockMove('2026-03-20', 'p1', 'w1', T::Sale, -4),
    ], []);

    expect($records)->toBe([])->and($calc->lastSkipped)->toBe(['no_demand' => 1, 'too_few_in_stock_days' => 0]);
});

it('writes at exactly min_in_stock_days days with stock, skips below it and counts the skip', function () {
    // Остаток кончается после 7-й продажи (4–10 марта): дней с остатком на начало дня ровно 7.
    $seven = array_slice(dailySales('p1', 'w1', 1), 0, 7);
    [$atMin] = daysOfStock($seven, ['p1|w1' => 7.0]);

    $six = array_slice(dailySales('p2', 'w1', 1), 0, 6);
    [$belowMin, $calc] = daysOfStock($six, ['p2|w1' => 6.0]);

    expect($atMin['p1:w1']->valueMeta['in_stock_days'])->toBe(7)
        ->and($atMin['p1:w1']->value)->toBe(0.0)
        ->and($belowMin)->toBe([])
        ->and($calc->lastSkipped['too_few_in_stock_days'])->toBe(1);
});

it('respects a custom window and threshold', function () {
    [$records] = daysOfStock(dailySales('p1', 'w1', 1, '2026-03-25', '2026-03-31'), ['p1|w1' => 20.0], new DaysOfStockCalculator(7, 3));

    expect($records['p1:w1']->valueMeta)->toMatchArray(['window_days' => 7, 'in_stock_days' => 7, 'stock_qty' => 13.0])
        ->and($records['p1:w1']->value)->toBe(13.0);
});

it('builds the composite entity id in one place', function () {
    expect(ProductWarehouseKey::make('prod-1', 'wh-2'))->toBe('prod-1:wh-2')
        ->and(ProductWarehouseKey::parse('prod-1:wh-2'))->toBe(['prod-1', 'wh-2']);

    ProductWarehouseKey::make('a:b', 'w');
})->throws(InvalidArgumentException::class);
