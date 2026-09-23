<?php

use App\Console\Commands\CalculateMetrics;
use App\Core\Domain\DateRange;

function resolvePeriodOf(CalculateMetrics $command, ?string $value, $adapter): DateRange
{
    $method = new ReflectionMethod(CalculateMetrics::class, 'resolvePeriod');

    return $method->invoke($command, $value, $adapter);
}

it('falls back to 12 months ending this month for an adapter without ProvidesHistoryBounds', function () {
    $command = app(CalculateMetrics::class);

    $range = resolvePeriodOf($command, null, fakeAdapter());

    $now = new DateTimeImmutable('first day of this month');
    expect($range->start->format('Y-m-d'))->toBe($now->modify('-11 months')->format('Y-m-d'))
        ->and($range->end->format('Y-m-d'))->toBe($now->modify('last day of this month')->format('Y-m-d'));
});

it('still honors an explicit --period for an adapter without ProvidesHistoryBounds', function () {
    $command = app(CalculateMetrics::class);

    $range = resolvePeriodOf($command, '2026-01:2026-02', fakeAdapter());

    expect($range->start->format('Y-m-d'))->toBe('2026-01-01')
        ->and($range->end->format('Y-m-d'))->toBe('2026-02-28');
});
