<?php

use App\Core\Analytics\XyzClassifier;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

it('classifies stable demand as X, volatile demand as Z, and no-demand product as Z without division by zero', function () {
    $deals = [
        // prod-1: стабильный спрос 100 в каждом из трёх месяцев -> CV=0 -> X.
        new Deal('deal-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-10')),
        new Deal('deal-2', 'prod-1', 100.0, new DateTimeImmutable('2026-02-10')),
        new Deal('deal-3', 'prod-1', 100.0, new DateTimeImmutable('2026-03-10')),

        // prod-2: резкий скачок в одном месяце из трёх -> высокий CV -> Z.
        new Deal('deal-4', 'prod-2', 1000.0, new DateTimeImmutable('2026-01-10')),

        // prod-3: единственная сделка на 0 — mean по трём месяцам = 0,
        // допущение №4: CV не определён -> класс Z, без деления на 0.
        new Deal('deal-5', 'prod-3', 0.0, new DateTimeImmutable('2026-01-10')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

    $records = (new XyzClassifier)->calculate(fakeAdapter($deals), $period);
    $byId = collect($records)->keyBy('entityId');

    expect($byId['prod-1']->valueMeta)->toBe(['xyz_class' => 'X']);
    expect($byId['prod-1']->value)->toBe(0.0);

    expect($byId['prod-2']->valueMeta)->toBe(['xyz_class' => 'Z']);
    expect($byId['prod-2']->value)->toBeGreaterThan(0.25);

    expect($byId['prod-3']->valueMeta)->toBe(['xyz_class' => 'Z']);
    expect($byId['prod-3']->value)->toBe(0.0);

    foreach ($records as $record) {
        expect($record->entityType)->toBe('product');
        expect($record->metricKey)->toBe('abc_xyz_classification');
        expect($record->period)->toBe('2026-03');
    }
});

it('returns no records when there are no deals in the period', function () {
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

    $records = (new XyzClassifier)->calculate(fakeAdapter([]), $period);

    expect($records)->toBe([]);
});
