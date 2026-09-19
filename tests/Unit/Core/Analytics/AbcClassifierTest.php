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
