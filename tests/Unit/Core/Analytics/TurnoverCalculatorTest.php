<?php

use App\Core\Analytics\TurnoverCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

it('computes turnover as unitsSold / avg(opening, closing) for a normal month', function () {
    $movements = [
        new StockMovement('m-1', 'prod-1', 'wh-1', 100.0, StockMovementType::Receipt, new DateTimeImmutable('2026-01-05')),
        new StockMovement('m-2', 'prod-1', 'wh-1', -20.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-15')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new TurnoverCalculator)->calculate($movements, $period);
    $byPeriod = collect($records)->where('entityId', 'prod-1')->keyBy('period');

    // Январь: opening=0, closing=0+100-20=80, avgStock=(0+80)/2=40, unitsSold=20 -> 20/40.
    expect($byPeriod['month:2026-01']->value)->toBe(20.0 / 40.0);
    // Февраль: без движений, opening=closing=80, avgStock=80, unitsSold=0 -> 0/80=0 (записывается, не пропускается).
    expect($byPeriod['month:2026-02']->value)->toBe(0.0);

    foreach ($records as $record) {
        expect($record->entityType)->toBe('product');
        expect($record->metricKey)->toBe('turnover');
        expect(array_keys($record->valueMeta))->toBe(['units_sold', 'avg_stock', 'opening_stock', 'closing_stock']);
    }
    expect($byPeriod['month:2026-01']->valueMeta)->toBe(['units_sold' => 20.0, 'avg_stock' => 40.0, 'opening_stock' => 0.0, 'closing_stock' => 80.0]);
});

it('does not write a snapshot for a month where avgStock is zero or negative', function () {
    // Только sale, без receipt — сальдо товара уходит в минус весь период.
    $movements = [
        new StockMovement('m-1', 'prod-2', 'wh-1', -30.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new TurnoverCalculator)->calculate($movements, $period);
    $forProduct = collect($records)->where('entityId', 'prod-2');

    expect($forProduct)->toHaveCount(0);
});

it('does not let a Transfer between warehouses affect the product balance', function () {
    $movements = [
        new StockMovement('m-1', 'prod-3', 'wh-1', 100.0, StockMovementType::Receipt, new DateTimeImmutable('2026-01-02')),
        new StockMovement('m-2a', 'prod-3', 'wh-1', -30.0, StockMovementType::TransferOut, new DateTimeImmutable('2026-01-10')),
        new StockMovement('m-2b', 'prod-3', 'wh-2', 30.0, StockMovementType::TransferIn, new DateTimeImmutable('2026-01-10')),
        new StockMovement('m-3', 'prod-3', 'wh-1', -10.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-20')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $records = (new TurnoverCalculator)->calculate($movements, $period);
    $january = collect($records)->firstWhere('entityId', 'prod-3');

    // Если бы transfer ошибочно влиял на сальдо (например, как ещё
    // один расход), closing было бы 60, avgStock=30, turnover=10/30.
    // Правильное значение (Transfer не меняет сальдо товара):
    // closing=90, avgStock=45, turnover=10/45.
    expect($january->value)->toBe(10.0 / 45.0);
});

it('takes the real opening stock into account: opening 130, sold 62 → closing 68, avg 99', function () {
    $movements = [new StockMovement('m-1', 'p1', 'w1', -62.0, StockMovementType::Sale, new DateTimeImmutable('2026-08-10'))];
    $period = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

    $records = (new TurnoverCalculator)->calculate($movements, $period, ['p1' => 130.0]);

    expect($records)->toHaveCount(1)
        ->and($records[0]->value)->toEqualWithDelta(62 / 99, 1e-12)
        ->and($records[0]->value)->toEqualWithDelta(0.6263, 1e-4)
        ->and($records[0]->valueMeta)->toBe(['units_sold' => 62.0, 'avg_stock' => 99.0, 'opening_stock' => 130.0, 'closing_stock' => 68.0]);
});

it('writes turnover 0 for every month for a product with stock and no movements', function () {
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

    $records = (new TurnoverCalculator)->calculate([], $period, ['p1' => 50.0]);

    expect(array_map(fn ($r) => $r->period, $records))->toBe(['month:2026-01', 'month:2026-02', 'month:2026-03'])
        ->and(array_map(fn ($r) => $r->value, $records))->toBe([0.0, 0.0, 0.0])
        ->and($records[2]->valueMeta)->toBe(['units_sold' => 0.0, 'avg_stock' => 50.0, 'opening_stock' => 50.0, 'closing_stock' => 50.0]);
});

it('does not write rows for products with neither movements nor positive opening stock', function () {
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    expect((new TurnoverCalculator)->calculate([], $period, ['p1' => 0.0, 'p2' => -3.0]))->toBe([]);
});

it('uses a receipt in the middle of the range: opening of the next month is the closing of the previous', function () {
    $movements = [
        new StockMovement('m-1', 'p1', 'w1', -10.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-10')),
        new StockMovement('m-2', 'p1', 'w1', 100.0, StockMovementType::Receipt, new DateTimeImmutable('2026-02-05')),
        new StockMovement('m-3', 'p1', 'w1', -30.0, StockMovementType::Sale, new DateTimeImmutable('2026-02-20')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

    $byPeriod = collect((new TurnoverCalculator)->calculate($movements, $period, ['p1' => 20.0]))->keyBy('period');

    // Январь: 20 → 10, avg 15, продано 10. Февраль: 10 → 80, avg 45, продано 30. Март: 80 → 80, avg 80, продано 0.
    expect($byPeriod['month:2026-01']->valueMeta)->toMatchArray(['opening_stock' => 20.0, 'closing_stock' => 10.0, 'avg_stock' => 15.0])
        ->and($byPeriod['month:2026-01']->value)->toBe(10.0 / 15.0)
        ->and($byPeriod['month:2026-02']->valueMeta)->toMatchArray(['opening_stock' => 10.0, 'closing_stock' => 80.0, 'avg_stock' => 45.0])
        ->and($byPeriod['month:2026-02']->value)->toBe(30.0 / 45.0)
        ->and($byPeriod['month:2026-03']->valueMeta)->toMatchArray(['opening_stock' => 80.0, 'closing_stock' => 80.0])
        ->and($byPeriod['month:2026-03']->value)->toBe(0.0);
});

it('does not write a row when opening plus movements leave avgStock at or below zero', function () {
    $movements = [new StockMovement('m-1', 'p1', 'w1', -30.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-05'))];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    // opening 10, closing −20, avg −5 → строки нет.
    expect((new TurnoverCalculator)->calculate($movements, $period, ['p1' => 10.0]))->toBe([]);
});

it('keeps the previous behavior for the default empty opening stock (starts from zero)', function () {
    $movements = [
        new StockMovement('m-1', 'p1', 'w1', 100.0, StockMovementType::Receipt, new DateTimeImmutable('2026-01-05')),
        new StockMovement('m-2', 'p1', 'w1', -20.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-15')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));
    $calc = new TurnoverCalculator;

    expect($calc->calculate($movements, $period)[0]->value)->toBe($calc->calculate($movements, $period, [])[0]->value)
        ->and($calc->calculate($movements, $period)[0]->value)->toBe(20.0 / 40.0);
});

it('does not let transfer legs change the product balance, even when only one leg is in the stream', function () {
    $movements = [
        new StockMovement('m-1', 'p1', 'w1', -25.0, StockMovementType::TransferOut, new DateTimeImmutable('2026-01-05')),
        new StockMovement('m-2', 'p1', 'w2', 25.0, StockMovementType::TransferIn, new DateTimeImmutable('2026-01-05')),
        new StockMovement('m-3', 'p1', 'w1', -10.0, StockMovementType::Sale, new DateTimeImmutable('2026-01-06')),
        new StockMovement('m-4', 'p1', 'w2', 5.0, StockMovementType::TransferIn, new DateTimeImmutable('2026-01-07')), // вторая ножка не пришла
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $record = (new TurnoverCalculator)->calculate($movements, $period, ['p1' => 100.0])[0];

    expect($record->valueMeta)->toMatchArray(['opening_stock' => 100.0, 'closing_stock' => 90.0]);
});
