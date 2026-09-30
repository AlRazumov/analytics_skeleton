<?php

use App\Core\Analytics\AbcClassifier;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

it('classifies by the cumulative revenue share accumulated before the product', function () {
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

    // Доля до prod-1 — 0, он A (даже если сам пересекает 0.8).
    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'A']);
    // Доля до prod-2 ровно 0.8 — уже не < 0.8, поэтому B.
    expect($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'B']);
    // Доля до prod-3 ровно 0.95 — не < 0.95, поэтому C.
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

    // Доли — от суммы положительных нетто (120): до prod-1 — 0 → A, до prod-2 — 0.83 → B.
    // Раньше итог был 90 и prod-1 получал долю 1.11 → C.
    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'A'])
        ->and($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'B'])
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
        ->and($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'B'])
        ->and($byId['prod-3']->valueMeta)->toBe(['abc_class' => 'C']);
});

it('keeps a dominant product in class A', function () {
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $single = collect((new AbcClassifier)->calculate([
        new Deal('d-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-05')),
    ], $period))->keyBy('entityId');
    expect($single['prod-1']->valueMeta)->toBe(['abc_class' => 'A']);

    // prod-1 даёт 96% выручки: раньше получал C (накопленная доля 0.96 > 0.95).
    $dominant = collect((new AbcClassifier)->calculate([
        new Deal('d-1', 'prod-1', 960.0, new DateTimeImmutable('2026-01-05')),
        new Deal('d-2', 'prod-2', 30.0, new DateTimeImmutable('2026-01-06')),
        new Deal('d-3', 'prod-3', 10.0, new DateTimeImmutable('2026-01-07')),
    ], $period))->keyBy('entityId');
    expect($dominant['prod-1']->valueMeta)->toBe(['abc_class' => 'A'])
        // Доля до prod-2 — 0.96, до prod-3 — 0.99: оба уже за порогом 0.95.
        ->and($dominant['prod-2']->valueMeta)->toBe(['abc_class' => 'C'])
        ->and($dominant['prod-3']->valueMeta)->toBe(['abc_class' => 'C']);
});
