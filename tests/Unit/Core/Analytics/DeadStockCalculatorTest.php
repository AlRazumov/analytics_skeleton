<?php

use App\Core\Analytics\DeadStockCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType as T;

function deadStock(array $movements, array $opening, string $from, string $to, int $threshold = 90): array
{
    $range = new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($to));

    return collect((new DeadStockCalculator($threshold))->calculate(stubStockAdapter($movements, $opening), $range))
        ->keyBy(fn ($r) => $r->entityId.'@'.$r->period)->all();
}

it('counts days from the last sale to asOf and reports stock and threshold', function () {
    $records = deadStock([
        stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 100),
        stockMove('2026-03-10', 'p1', 'w1', T::Sale, -5),
    ], [], '2026-01-01', '2026-06-30');

    // 2026-03-10 → 2026-06-30 = 112 дней.
    expect($records['p1@month:2026-06']->value)->toBe(112.0)
        ->and($records['p1@month:2026-06']->valueMeta)->toBe(['stock_qty' => 95.0, 'threshold_days' => 90])
        ->and($records['p1@month:2026-03']->value)->toBe(21.0);
});

it('writes exactly-at-threshold values so that value >= threshold picks them up', function () {
    $records = deadStock([
        stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 10),
        stockMove('2026-01-01', 'p1', 'w1', T::Sale, -1),
    ], [], '2026-01-01', '2026-04-01');

    // 2026-01-01 → 2026-04-01 = 90 дней.
    expect($records['p1@month:2026-04']->value)->toBe(90.0);
});

it('does not write a snapshot for a month in which the stock is zero at asOf', function () {
    $records = deadStock([
        stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 10),
        stockMove('2026-01-05', 'p1', 'w1', T::Sale, -10),
        stockMove('2026-02-10', 'p1', 'w1', T::Receipt, 4),
    ], [], '2026-01-01', '2026-02-28');

    expect($records)->not->toHaveKey('p1@month:2026-01')
        ->and($records['p1@month:2026-02']->valueMeta['stock_qty'])->toBe(4.0)
        // Продажа была 5 января, приход её «не освежает».
        ->and($records['p1@month:2026-02']->value)->toBe(54.0);
});

it('uses days since range start and flags no_sales_in_history when nothing was sold', function () {
    $records = deadStock([stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 10)], [], '2026-01-01', '2026-03-31');

    // 2026-01-01 → 2026-03-31 = 89 дней.
    expect($records['p1@month:2026-03']->value)->toBe(89.0)
        ->and($records['p1@month:2026-03']->valueMeta)->toMatchArray(['no_sales_in_history' => true, 'stock_qty' => 10.0]);
});

it('does not treat transfers, writeoffs and adjustments as sales', function () {
    $records = deadStock([
        stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 20),
        stockMove('2026-01-02', 'p1', 'w1', T::Sale, -1),
        stockMove('2026-02-20', 'p1', 'w1', T::TransferOut, -3),
        stockMove('2026-02-20', 'p1', 'w2', T::TransferIn, 3),
        stockMove('2026-02-21', 'p1', 'w1', T::Writeoff, -1),
        stockMove('2026-02-22', 'p1', 'w1', T::Adjustment, -1),
    ], [], '2026-01-01', '2026-02-28');

    expect($records['p1@month:2026-02']->value)->toBe(57.0)          // с 2 января
        ->and($records['p1@month:2026-02']->valueMeta['stock_qty'])->toBe(17.0); // переводы в сумме 0
});

it('sums stock across warehouses and takes the last sale across warehouses', function () {
    $records = deadStock([
        stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 5),
        stockMove('2026-01-01', 'p1', 'w2', T::Receipt, 7),
        stockMove('2026-01-10', 'p1', 'w1', T::Sale, -1),
        stockMove('2026-01-20', 'p1', 'w2', T::Sale, -1),
    ], [], '2026-01-01', '2026-01-31');

    expect($records['p1@month:2026-01']->value)->toBe(11.0)
        ->and($records['p1@month:2026-01']->valueMeta['stock_qty'])->toBe(10.0);
});

it('takes the opening stock from fetchStock instead of summing the whole history', function () {
    // 50 шт. лежали до диапазона; в диапазоне ни одного движения.
    $records = deadStock([], ['p1|w1' => 50.0], '2026-02-01', '2026-02-28');

    expect($records['p1@month:2026-02']->valueMeta)->toMatchArray(['stock_qty' => 50.0, 'no_sales_in_history' => true])
        ->and($records['p1@month:2026-02']->value)->toBe(27.0);
});

it('is deterministic: the same input gives identical records', function () {
    $movements = [stockMove('2026-01-01', 'p1', 'w1', T::Receipt, 10), stockMove('2026-01-09', 'p1', 'w1', T::Sale, -1)];

    expect(deadStock($movements, [], '2026-01-01', '2026-03-31'))->toEqual(deadStock($movements, [], '2026-01-01', '2026-03-31'));
});
