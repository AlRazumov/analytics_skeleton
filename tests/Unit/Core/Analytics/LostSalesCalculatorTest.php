<?php

use App\Core\Analytics\LostSalesCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;

it('flags a product with sales last period and none this period', function () {
    $deals = [
        new Deal('d-1', 'a', 100.0, new DateTimeImmutable('2026-06-05')),
        new Deal('d-2', 'a', 50.0, new DateTimeImmutable('2026-07-05')),
        // 'a' has no deals in August. 'b' sells every month — not lost.
        new Deal('d-3', 'b', 10.0, new DateTimeImmutable('2026-06-05')),
        new Deal('d-4', 'b', 10.0, new DateTimeImmutable('2026-07-05')),
        new Deal('d-5', 'b', 10.0, new DateTimeImmutable('2026-08-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'));

    $records = (new LostSalesCalculator(horizonMonths: 1))->calculate($deals, $period);

    expect($records)->toHaveCount(1);
    $record = $records[0];
    expect($record->entityType)->toBe('product')
        ->and($record->entityId)->toBe('a')
        ->and($record->metricKey)->toBe('lost_sales')
        ->and($record->period)->toBe('month:2026-08')
        ->and($record->value)->toBe(50.0)
        ->and($record->valueMeta)->toBe(['horizon_months' => 1, 'last_sale_period' => 'month:2026-07']);
});

it('does not flag the first month of the range (the prior month is outside the queried range, invisible)', function () {
    $deals = [
        new Deal('d-1', 'a', 100.0, new DateTimeImmutable('2026-06-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'));

    $records = (new LostSalesCalculator(horizonMonths: 1))->calculate($deals, $period);

    // July is flagged (June, inside the range, had sales). August is not:
    // with horizon 1 the only month checked (July) has no sales either.
    $periods = array_map(fn ($r) => $r->period, $records);
    expect($periods)->toBe(['month:2026-07']);
});

it('respects a wider horizon: looks back several months for the last sale', function () {
    $deals = [
        new Deal('d-1', 'a', 100.0, new DateTimeImmutable('2026-05-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-05-01'), new DateTimeImmutable('2026-08-31'));

    $records = (new LostSalesCalculator(horizonMonths: 3))->calculate($deals, $period);

    $byPeriod = collect($records)->keyBy('period');
    // June, July, August all lack sales; horizon 3 reaches back to May for all of them.
    expect($byPeriod->keys()->all())->toBe(['month:2026-06', 'month:2026-07', 'month:2026-08']);
    foreach ($byPeriod as $period => $record) {
        expect($record->valueMeta['last_sale_period'])->toBe('month:2026-05');
    }
});

it('does not flag a product with no sales at all in the horizon (never sold, not "lost")', function () {
    $deals = [
        new Deal('d-1', 'other', 10.0, new DateTimeImmutable('2026-08-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'));

    $records = (new LostSalesCalculator(horizonMonths: 1))->calculate($deals, $period);

    expect($records)->toBe([]);
});

it('validates horizonMonths', function () {
    expect(fn () => new LostSalesCalculator(0))->toThrow(InvalidArgumentException::class);
});
