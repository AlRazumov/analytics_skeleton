<?php

use App\Core\Analytics\RevenueByPeriodCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

it('aggregates revenue by (product, month)', function () {
    $deals = [
        new Deal('deal-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-05')),
        new Deal('deal-2', 'prod-1', 50.0, new DateTimeImmutable('2026-01-20')),
        new Deal('deal-3', 'prod-1', 30.0, new DateTimeImmutable('2026-02-01')),
        new Deal('deal-4', 'prod-2', 20.0, new DateTimeImmutable('2026-01-10')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new RevenueByPeriodCalculator)->calculate(fakeAdapter($deals), $period);

    $byKey = collect($records)->keyBy(fn ($r) => $r->entityId.'|'.$r->period);

    expect($records)->toHaveCount(3);
    expect($byKey['prod-1|2026-01']->value)->toBe(150.0);
    expect($byKey['prod-1|2026-02']->value)->toBe(30.0);
    expect($byKey['prod-2|2026-01']->value)->toBe(20.0);

    foreach ($records as $record) {
        expect($record->entityType)->toBe('product');
        expect($record->metricKey)->toBe('revenue');
        expect($record->valueMeta)->toBe([]);
    }
});
