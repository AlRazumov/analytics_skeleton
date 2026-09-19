<?php

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;

function d(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd);
}

it('exposes inclusive date bounds for every granularity', function (string $key, string $start, string $end) {
    $period = Period::fromKey($key);

    expect($period->start->format('Y-m-d'))->toBe($start)
        ->and($period->end->format('Y-m-d'))->toBe($end)
        ->and($period->key())->toBe($key);
})->with([
    ['day:2026-08-19', '2026-08-19', '2026-08-19'],
    ['week:2026-W34', '2026-08-17', '2026-08-23'],
    ['month:2026-08', '2026-08-01', '2026-08-31'],
    ['month:2028-02', '2028-02-01', '2028-02-29'],
    ['month:2027-02', '2027-02-01', '2027-02-28'],
    ['quarter:2026-Q3', '2026-07-01', '2026-09-30'],
    ['quarter:2026-Q1', '2026-01-01', '2026-03-31'],
    ['year:2026', '2026-01-01', '2026-12-31'],
    ['week:2026-W01', '2025-12-29', '2026-01-04'],
    ['week:2020-W53', '2020-12-28', '2021-01-03'],
]);

it('contains dates inclusively at both ends', function () {
    $month = Period::fromKey('month:2026-08');

    expect($month->contains(d('2026-08-01')))->toBeTrue()
        ->and($month->contains(d('2026-08-31 23:59:59')))->toBeTrue()
        ->and($month->contains(d('2026-07-31')))->toBeFalse()
        ->and($month->contains(d('2026-09-01')))->toBeFalse();
});

it('finds the period containing a date', function () {
    expect(Period::containing(PeriodGranularity::Week, d('2026-01-01'))->key())->toBe('week:2026-W01')
        ->and(Period::containing(PeriodGranularity::Week, d('2027-01-01'))->key())->toBe('week:2026-W53')
        ->and(Period::containing(PeriodGranularity::Quarter, d('2026-09-30'))->key())->toBe('quarter:2026-Q3');
});

it('computes previous period across year boundaries', function (string $key, string $expected) {
    expect(Period::fromKey($key)->previous()->key())->toBe($expected);
})->with([
    ['day:2026-01-01', 'day:2025-12-31'],
    ['day:2026-03-01', 'day:2026-02-28'],
    ['week:2026-W01', 'week:2025-W52'],
    ['week:2021-W01', 'week:2020-W53'],
    ['month:2026-01', 'month:2025-12'],
    ['month:2028-03', 'month:2028-02'],
    ['quarter:2026-Q1', 'quarter:2025-Q4'],
    ['quarter:2026-Q3', 'quarter:2026-Q2'],
    ['year:2026', 'year:2025'],
]);

it('computes the same period a year ago', function (string $key, string $expected) {
    expect(Period::fromKey($key)->yearAgo()->key())->toBe($expected);
})->with([
    ['month:2028-02', 'month:2027-02'],
    ['month:2027-02', 'month:2026-02'],
    ['month:2026-01', 'month:2025-01'],
    ['day:2028-02-29', 'day:2027-02-28'],
    ['day:2026-08-19', 'day:2025-08-19'],
    ['week:2026-W34', 'week:2025-W34'],
    ['week:2026-W53', 'week:2025-W52'],
    ['quarter:2026-Q3', 'quarter:2025-Q3'],
    ['year:2026', 'year:2025'],
]);

it('leap-year month yearAgo keeps correct bounds', function () {
    $feb = Period::fromKey('month:2028-02')->yearAgo();

    expect($feb->end->format('Y-m-d'))->toBe('2027-02-28');
});

it('roundtrips key and fromKey', function (string $key) {
    expect(Period::fromKey($key)->key())->toBe($key);
})->with([
    'day:2026-08-19', 'week:2026-W34', 'month:2026-08', 'quarter:2026-Q3', 'year:2026',
]);

it('rejects invalid keys', function (string $key) {
    Period::fromKey($key);
})->with([
    '', 'month', '2026-08', 'month:2026-13', 'month:2026-8', 'day:2026-02-30', 'day:2026-8-19',
    'week:2026-W00', 'week:2026-W54', 'week:2025-W53', 'quarter:2026-Q5', 'quarter:2026-Q0',
    'year:26', 'decade:2020', 'month:2026-08 ',
])->throws(InvalidArgumentException::class);

it('validates that bounds form a calendar period', function () {
    new Period(PeriodGranularity::Month, d('2026-08-02'), d('2026-08-31'));
})->throws(InvalidArgumentException::class);

it('rejects a month whose end is not the last day', function () {
    new Period(PeriodGranularity::Month, d('2026-08-01'), d('2026-08-30'));
})->throws(InvalidArgumentException::class);

it('is immutable', function () {
    $period = Period::fromKey('month:2026-08');
    $period->start = d('2020-01-01');
})->throws(Error::class);
