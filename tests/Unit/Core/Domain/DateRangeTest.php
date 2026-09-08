<?php

use App\Core\Domain\DateRange;

it('constructs with given values', function () {
    $start = new DateTimeImmutable('2026-01-01');
    $end = new DateTimeImmutable('2026-01-31');
    $range = new DateRange($start, $end);

    expect($range->start)->toBe($start)
        ->and($range->end)->toBe($end);
});

it('is immutable', function () {
    $range = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    expect(fn () => $range->start = new DateTimeImmutable())->toThrow(Error::class);
});
