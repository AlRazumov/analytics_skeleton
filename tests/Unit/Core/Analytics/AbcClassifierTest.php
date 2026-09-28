<?php

use App\Core\Analytics\AbcClassifier;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

it('classifies by cumulative revenue share with inclusive Pareto boundaries', function () {
    // Тотал 1000: prod-1=800 (кумулятивная доля ровно 0.8), prod-2=150
    // (кумулятивная доля ровно 0.95), prod-3=50 (остаток).
    $deals = [
        new Deal('deal-1', 'prod-1', 800.0, new DateTimeImmutable('2026-01-05')),
        new Deal('deal-2', 'prod-2', 150.0, new DateTimeImmutable('2026-01-10')),
        new Deal('deal-3', 'prod-3', 50.0, new DateTimeImmutable('2026-01-15')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $records = (new AbcClassifier)->calculate($deals, $period);
    $byId = collect($records)->keyBy('entityId');

    // Граница 0.8: товар, кумулятивная доля которого РОВНО 0.8,
    // попадает в A (порог инклюзивный, <=).
    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'A']);
    // Граница 0.95: товар с кумулятивной долей ровно 0.95 попадает в B
    // (тоже инклюзивный порог).
    expect($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'B']);
    expect($byId['prod-3']->valueMeta)->toBe(['abc_class' => 'C']);

    foreach ($records as $record) {
        expect($record->entityType)->toBe('product');
        expect($record->metricKey)->toBe('abc_xyz_classification');
        expect($record->period)->toBe('month:2026-01');
    }

    expect($byId['prod-1']->value)->toBe(800.0);
});

it('returns no records when there are no deals in the period', function () {
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $records = (new AbcClassifier)->calculate([], $period);

    expect($records)->toBe([]);
});

it('keeps the cumulative share within 1 when some products are net negative, and puts them in C', function () {
    $deals = [
        new Deal('d-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-05')),
        new Deal('d-2', 'prod-2', 20.0, new DateTimeImmutable('2026-01-06')),
        // prod-3: возврат больше продаж периода (продажа была раньше).
        new Deal('r-3', 'prod-3', -30.0, new DateTimeImmutable('2026-01-07')),
        // prod-4: продажа полностью возвращена, нетто 0.
        new Deal('d-4', 'prod-4', 10.0, new DateTimeImmutable('2026-01-08')),
        new Deal('r-4', 'prod-4', -10.0, new DateTimeImmutable('2026-01-09')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $byId = collect((new AbcClassifier)->calculate($deals, $period))->keyBy('entityId');

    // Доли — от суммы положительных нетто (120): prod-1 — 0.83 → B, prod-2 — 1.0 → C.
    // Раньше итог был 90 и prod-1 получал долю 1.11 → C.
    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'B'])
        ->and($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'C'])
        ->and($byId['prod-3']->valueMeta)->toBe(['abc_class' => 'C'])
        ->and($byId['prod-3']->value)->toBe(-30.0)
        ->and($byId['prod-4']->valueMeta)->toBe(['abc_class' => 'C'])
        ->and($byId['prod-4']->value)->toBe(0.0);
});

it('classifies products normally when returns of other products push the grand net total to zero or below', function () {
    $deals = [
        new Deal('d-1', 'prod-1', 80.0, new DateTimeImmutable('2026-01-05')),
        new Deal('d-2', 'prod-2', 20.0, new DateTimeImmutable('2026-01-06')),
        new Deal('r-3', 'prod-3', -150.0, new DateTimeImmutable('2026-01-07')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $byId = collect((new AbcClassifier)->calculate($deals, $period))->keyBy('entityId');

    // Раньше при итоге ≤ 0 все товары становились C.
    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'A'])
        ->and($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'C'])
        ->and($byId['prod-3']->valueMeta)->toBe(['abc_class' => 'C']);
});
