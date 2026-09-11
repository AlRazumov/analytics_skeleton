<?php

use App\Core\Analytics\TurnoverCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

it('computes turnover as unitsSold / avg(opening, closing) for a normal month', function () {
    $movements = [
        new StockMovement('m-1', 'prod-1', 'wh-1', 100.0, StockMovementType::In, new DateTimeImmutable('2026-01-05')),
        new StockMovement('m-2', 'prod-1', 'wh-1', 20.0, StockMovementType::Out, new DateTimeImmutable('2026-01-15')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new TurnoverCalculator)->calculate(fakeAdapter([], $movements), $period);
    $byPeriod = collect($records)->where('entityId', 'prod-1')->keyBy('period');

    // Январь: opening=0, closing=0+100-20=80, avgStock=(0+80)/2=40, unitsSold=20 -> 20/40.
    expect($byPeriod['2026-01']->value)->toBe(20.0 / 40.0);
    // Февраль: без движений, opening=closing=80, avgStock=80, unitsSold=0 -> 0/80=0 (записывается, не пропускается).
    expect($byPeriod['2026-02']->value)->toBe(0.0);

    foreach ($records as $record) {
        expect($record->entityType)->toBe('product');
        expect($record->metricKey)->toBe('turnover');
        expect($record->valueMeta)->toBe([]);
    }
});

it('does not write a snapshot for a month where avgStock is zero or negative', function () {
    // Только Out, без In — сальдо товара уходит в минус весь период.
    $movements = [
        new StockMovement('m-1', 'prod-2', 'wh-1', 30.0, StockMovementType::Out, new DateTimeImmutable('2026-01-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new TurnoverCalculator)->calculate(fakeAdapter([], $movements), $period);
    $forProduct = collect($records)->where('entityId', 'prod-2');

    expect($forProduct)->toHaveCount(0);
});

it('does not let a Transfer between warehouses affect the product balance', function () {
    $movements = [
        new StockMovement('m-1', 'prod-3', 'wh-1', 100.0, StockMovementType::In, new DateTimeImmutable('2026-01-02')),
        new StockMovement('m-2', 'prod-3', 'wh-1', 30.0, StockMovementType::Transfer, new DateTimeImmutable('2026-01-10'), toWarehouseId: 'wh-2'),
        new StockMovement('m-3', 'prod-3', 'wh-1', 10.0, StockMovementType::Out, new DateTimeImmutable('2026-01-20')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $records = (new TurnoverCalculator)->calculate(fakeAdapter([], $movements), $period);
    $january = collect($records)->firstWhere('entityId', 'prod-3');

    // Если бы Transfer ошибочно влиял на сальдо (например, как ещё
    // один Out), closing было бы 60, avgStock=30, turnover=10/30.
    // Правильное значение (Transfer не меняет сальдо товара):
    // closing=90, avgStock=45, turnover=10/45.
    expect($january->value)->toBe(10.0 / 45.0);
});
