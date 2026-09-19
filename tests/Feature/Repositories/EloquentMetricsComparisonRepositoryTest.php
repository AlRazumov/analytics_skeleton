<?php

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, array<string, float>> $data periodKey => [entityId => value] */
function seedMetric(array $data, string $metricKey = 'revenue', string $entityType = 'product'): void
{
    $records = [];
    foreach ($data as $periodKey => $values) {
        foreach ($values as $entityId => $value) {
            $records[] = new MetricsSnapshotRecord($entityType, (string) $entityId, $metricKey, $value, $periodKey);
        }
    }

    (new EloquentMetricsSnapshotWriter)->write($records);
}

function compareRows(string $periodKey, ComparisonBase $base, string $metricKey = 'revenue'): array
{
    $rows = [];
    foreach ((new EloquentMetricsComparisonRepository)->compare($metricKey, 'product', Period::fromKey($periodKey), $base) as $row) {
        $rows[$row->entityId] = $row;
    }

    return $rows;
}

function topIds(string $periodKey, int $limit, RankBy $by = RankBy::Value, Direction $dir = Direction::Desc, ?ComparisonBase $base = null): array
{
    return array_map(
        fn ($r) => $r->entityId,
        (new EloquentMetricsComparisonRepository)->top('revenue', 'product', Period::fromKey($periodKey), $limit, $by, $dir, $base),
    );
}

it('compares with the previous period across a year boundary (2026-01 vs 2025-12)', function () {
    seedMetric(['month:2025-12' => ['a' => 100.0], 'month:2026-01' => ['a' => 150.0]]);

    $row = compareRows('month:2026-01', ComparisonBase::Previous)['a'];

    expect($row->value)->toBe(150.0)->and($row->baseValue)->toBe(100.0)
        ->and($row->deltaAbs)->toBe(50.0)->and($row->deltaPct)->toBe(50.0);
});

it('compares with the same period a year ago', function () {
    seedMetric(['month:2025-03' => ['a' => 200.0], 'month:2025-02' => ['a' => 1.0], 'month:2026-03' => ['a' => 100.0]]);

    $row = compareRows('month:2026-03', ComparisonBase::YearAgo)['a'];

    expect($row->baseValue)->toBe(200.0)->and($row->deltaAbs)->toBe(-100.0)->and($row->deltaPct)->toBe(-50.0);
});

it('gives a null base — not the month before last — when the previous snapshot is missing', function () {
    // Есть 2026-01 и 2026-03, нет 2026-02: для марта база — null, а не январь.
    seedMetric(['month:2026-01' => ['a' => 10.0], 'month:2026-03' => ['a' => 30.0]]);

    $row = compareRows('month:2026-03', ComparisonBase::Previous)['a'];

    expect($row->value)->toBe(30.0)->and($row->baseValue)->toBeNull()
        ->and($row->deltaAbs)->toBeNull()->and($row->deltaPct)->toBeNull();
});

it('gives a null deltaPct but a correct deltaAbs when the base is zero', function () {
    seedMetric(['month:2026-01' => ['a' => 0.0], 'month:2026-02' => ['a' => 40.0]]);

    $row = compareRows('month:2026-02', ComparisonBase::Previous)['a'];

    expect($row->baseValue)->toBe(0.0)->and($row->deltaAbs)->toBe(40.0)->and($row->deltaPct)->toBeNull();
});

it('returns only entities of the current period and orders by entity_id', function () {
    seedMetric([
        'month:2026-01' => ['gone' => 5.0, 'b' => 1.0],
        'month:2026-02' => ['b' => 2.0, 'a' => 3.0, 'new' => 4.0],
    ]);

    $rows = compareRows('month:2026-02', ComparisonBase::Previous);

    expect(array_keys($rows))->toBe(['a', 'b', 'new'])   // 'gone' пропал в текущем — не возвращается
        ->and($rows['a']->baseValue)->toBeNull()
        ->and($rows['b']->deltaAbs)->toBe(1.0);
});

it('joins by exact period type, metric and entity type', function () {
    seedMetric(['month:2026-01' => ['a' => 10.0], 'quarter:2025-Q4' => ['a' => 999.0]]);
    seedMetric(['month:2026-01' => ['a' => 555.0]], metricKey: 'other');
    seedMetric(['month:2026-01' => ['a' => 777.0]], entityType: 'product_warehouse');
    seedMetric(['month:2026-02' => ['a' => 20.0]]);

    expect(compareRows('month:2026-02', ComparisonBase::Previous)['a']->baseValue)->toBe(10.0);

    seedMetric(['quarter:2026-Q1' => ['a' => 1.0]]);
    expect(compareRows('quarter:2026-Q1', ComparisonBase::Previous)['a']->baseValue)->toBe(999.0);
});

it('ranks by value in both directions', function () {
    seedMetric(['month:2026-02' => ['a' => 10.0, 'b' => 30.0, 'c' => 20.0, 'd' => 40.0]]);

    expect(topIds('month:2026-02', 2))->toBe(['d', 'b'])
        ->and(topIds('month:2026-02', 2, dir: Direction::Asc))->toBe(['a', 'c']);
});

it('breaks ties by entity_id ascending in both directions', function () {
    seedMetric(['month:2026-02' => ['prod-10' => 5.0, 'prod-2' => 5.0, 'prod-1' => 5.0, 'prod-3' => 9.0]]);

    // Побайтовое сравнение: prod-1 < prod-10 < prod-2.
    expect(topIds('month:2026-02', 4))->toBe(['prod-3', 'prod-1', 'prod-10', 'prod-2'])
        ->and(topIds('month:2026-02', 4, dir: Direction::Asc))->toBe(['prod-1', 'prod-10', 'prod-2', 'prod-3']);
});

it('returns all rows when limit exceeds their number', function () {
    seedMetric(['month:2026-02' => ['a' => 1.0, 'b' => 2.0]]);

    expect(topIds('month:2026-02', 1000))->toBe(['b', 'a']);
});

it('ranks by absolute delta including negative deltas and excludes rows without a base', function () {
    seedMetric([
        'month:2026-01' => ['up' => 10.0, 'down' => 100.0, 'flat' => 5.0],
        'month:2026-02' => ['up' => 30.0, 'down' => 60.0, 'flat' => 5.0, 'nobase' => 1000.0],
    ]);

    // Дельты: up +20, down −40, flat 0; nobase исключён.
    expect(topIds('month:2026-02', 10, RankBy::DeltaAbs, Direction::Desc, ComparisonBase::Previous))->toBe(['up', 'flat', 'down'])
        ->and(topIds('month:2026-02', 10, RankBy::DeltaAbs, Direction::Asc, ComparisonBase::Previous))->toBe(['down', 'flat', 'up']);
});

it('ranks by percent delta and excludes rows with a zero or missing base', function () {
    seedMetric([
        'month:2026-01' => ['a' => 100.0, 'b' => 10.0, 'zero' => 0.0, 'c' => 50.0],
        'month:2026-02' => ['a' => 150.0, 'b' => 30.0, 'zero' => 7.0, 'c' => 25.0, 'nobase' => 1.0],
    ]);

    // a +50 %, b +200 %, c −50 %; zero (база 0) и nobase исключены.
    expect(topIds('month:2026-02', 10, RankBy::DeltaPct, Direction::Desc, ComparisonBase::Previous))->toBe(['b', 'a', 'c'])
        ->and(topIds('month:2026-02', 10, RankBy::DeltaPct, Direction::Asc, ComparisonBase::Previous))->toBe(['c', 'a', 'b']);
});

it('carries base and deltas on top rows when a base is given, even when ranking by value', function () {
    seedMetric(['month:2026-01' => ['a' => 10.0], 'month:2026-02' => ['a' => 15.0]]);

    $rows = (new EloquentMetricsComparisonRepository)->top('revenue', 'product', Period::fromKey('month:2026-02'), 5, base: ComparisonBase::Previous);

    expect($rows)->toHaveCount(1)->and($rows[0]->deltaPct)->toBe(50.0);
});

it('rejects invalid arguments', function (callable $call) {
    $call(new EloquentMetricsComparisonRepository, Period::fromKey('month:2026-02'));
})->with([
    'limit 0' => [fn ($r, $p) => $r->top('revenue', 'product', $p, 0)],
    'limit 1001' => [fn ($r, $p) => $r->top('revenue', 'product', $p, 1001)],
    'negative limit' => [fn ($r, $p) => $r->top('revenue', 'product', $p, -5)],
    'delta abs without base' => [fn ($r, $p) => $r->top('revenue', 'product', $p, 5, RankBy::DeltaAbs)],
    'delta pct without base' => [fn ($r, $p) => $r->top('revenue', 'product', $p, 5, RankBy::DeltaPct, Direction::Asc)],
])->throws(InvalidArgumentException::class);

it('returns nothing for an empty period', function () {
    expect(topIds('month:2026-02', 5))->toBe([])
        ->and(compareRows('month:2026-02', ComparisonBase::Previous))->toBe([]);
});

it('works for other granularities', function () {
    seedMetric(['quarter:2025-Q4' => ['a' => 10.0], 'quarter:2026-Q1' => ['a' => 20.0]]);

    $row = compareRows('quarter:2026-Q1', ComparisonBase::Previous)['a'];

    expect($row->baseValue)->toBe(10.0)
        ->and(Period::fromKey('quarter:2026-Q1')->granularity)->toBe(PeriodGranularity::Quarter);
});

// --- фильтр по value, count, latestPeriod (этап 10) ---

function topFiltered(?float $min, ?float $max, int $limit = 100, Direction $dir = Direction::Asc): array
{
    return array_map(
        fn ($r) => $r->entityId,
        (new EloquentMetricsComparisonRepository)->top('revenue', 'product', Period::fromKey('month:2026-08'), $limit, RankBy::Value, $dir, null, $min, $max),
    );
}

it('filters top by value with inclusive bounds', function () {
    seedMetric(['month:2026-08' => ['a' => 10.0, 'b' => 20.0, 'c' => 30.0, 'd' => 40.0]]);

    expect(topFiltered(20.0, 30.0))->toBe(['b', 'c'])
        ->and(topFiltered(20.0, null))->toBe(['b', 'c', 'd'])
        ->and(topFiltered(null, 20.0))->toBe(['a', 'b'])
        ->and(topFiltered(null, null))->toBe(['a', 'b', 'c', 'd'])
        ->and(topFiltered(25.0, 26.0))->toBe([])
        ->and(topFiltered(30.0, 30.0))->toBe(['c']);
});

it('applies the filter before the limit', function () {
    seedMetric(['month:2026-08' => ['a' => 10.0, 'b' => 20.0, 'c' => 30.0, 'd' => 40.0]]);

    expect(topFiltered(20.0, null, 2, Direction::Desc))->toBe(['d', 'c']);
});

it('filters by the value of the current period, not of the base', function () {
    seedMetric(['month:2026-07' => ['a' => 500.0, 'b' => 1.0], 'month:2026-08' => ['a' => 10.0, 'b' => 20.0]]);

    $rows = (new EloquentMetricsComparisonRepository)->top(
        'revenue', 'product', Period::fromKey('month:2026-08'), 10, RankBy::Value, Direction::Asc, ComparisonBase::Previous, 15.0, null,
    );

    expect(array_map(fn ($r) => $r->entityId, $rows))->toBe(['b'])
        ->and($rows[0]->baseValue)->toBe(1.0);
});

it('rejects minValue greater than maxValue in top and count', function () {
    $repo = new EloquentMetricsComparisonRepository;
    $august = Period::fromKey('month:2026-08');

    expect(fn () => $repo->top('revenue', 'product', $august, 5, minValue: 10.0, maxValue: 5.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $repo->count('revenue', 'product', $august, 10.0, 5.0))->toThrow(InvalidArgumentException::class);
});

it('counts consistently with top without limit', function () {
    seedMetric([
        'month:2026-08' => ['a' => 10.0, 'b' => 20.0, 'c' => 30.0, 'd' => 40.0],
        'month:2026-07' => ['e' => 25.0],
    ]);
    seedMetric(['month:2026-08' => ['z' => 25.0]], 'other_metric');
    seedMetric(['month:2026-08' => ['w' => 25.0]], 'revenue', 'warehouse');
    $repo = new EloquentMetricsComparisonRepository;
    $august = Period::fromKey('month:2026-08');

    foreach ([[null, null], [20.0, 30.0], [20.0, null], [null, 20.0], [25.0, 26.0], [30.0, 30.0]] as [$min, $max]) {
        expect($repo->count('revenue', 'product', $august, $min, $max))->toBe(count(topFiltered($min, $max, 1000)));
    }
    expect($repo->count('revenue', 'product', $august))->toBe(4)
        ->and($repo->count('revenue', 'product', Period::fromKey('month:2026-06')))->toBe(0);
});

it('carries value_meta on top rows', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'a', 'revenue', 5.0, 'month:2026-08', ['stock_qty' => 7.5, 'flag' => true]),
        new MetricsSnapshotRecord('product', 'b', 'revenue', 6.0, 'month:2026-08'),
    ]);

    $rows = (new EloquentMetricsComparisonRepository)->top('revenue', 'product', Period::fromKey('month:2026-08'), 5, dir: Direction::Asc);

    expect($rows[0]->valueMeta)->toEqual(['stock_qty' => 7.5, 'flag' => true])
        ->and($rows[1]->valueMeta)->toBe([]);
});

it('finds the latest period of a metric without using the current time', function () {
    $repo = new EloquentMetricsComparisonRepository;

    expect($repo->latestPeriod('revenue', PeriodGranularity::Month))->toBeNull();

    seedMetric(['month:2026-03' => ['a' => 1.0], 'month:2026-08' => ['a' => 1.0], 'month:2025-12' => ['a' => 1.0]]);
    seedMetric(['month:2027-01' => ['a' => 1.0]], 'other_metric');
    seedMetric(['day:2026-09-15' => ['a' => 1.0], 'year:2030' => ['a' => 1.0]]);

    expect($repo->latestPeriod('revenue', PeriodGranularity::Month)->key())->toBe('month:2026-08')
        ->and($repo->latestPeriod('revenue', PeriodGranularity::Day)->key())->toBe('day:2026-09-15')
        ->and($repo->latestPeriod('revenue', PeriodGranularity::Week))->toBeNull()
        ->and($repo->latestPeriod('missing', PeriodGranularity::Month))->toBeNull();
});
