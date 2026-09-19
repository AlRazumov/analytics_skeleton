<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\RevenueByPeriodCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Независимый оракул: суммы выручки по товарам за месяц прямо из сырых
 * сделок мока (не через калькулятор и не через БД), округление как у numeric(20,4).
 *
 * @return array<string, array<string, float>> 'Y-m' => [productId => sum]
 */
function revenueOracle(MockAdapter $adapter, DateRange $range): array
{
    $sums = [];
    foreach ($adapter->fetchDeals($range) as $deal) {
        $month = $deal->date->format('Y-m');
        $sums[$month][$deal->productId] = ($sums[$month][$deal->productId] ?? 0.0) + $deal->amount;
    }

    return array_map(fn ($byProduct) => array_map(fn ($v) => round($v, 4), $byProduct), $sums);
}

function sortedByValue(array $values, int $direction = -1): array
{
    $ids = array_keys($values);
    usort($ids, fn ($a, $b) => ($direction * ($values[$a] <=> $values[$b])) ?: strcmp($a, $b));

    return $ids;
}

beforeEach(function () {
    $this->adapter = new MockAdapter(MockDataProfile::Small, 1);
    $this->range = new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-31'));
    $this->oracle = revenueOracle($this->adapter, $this->range);

    $deals = iterator_to_array($this->adapter->fetchDeals($this->range), false);
    (new EloquentMetricsSnapshotWriter)->write((new RevenueByPeriodCalculator)->calculate($deals, $this->range));

    $this->repository = new EloquentMetricsComparisonRepository;
    $this->august = Period::fromKey('month:2026-08');
});

it('returns the same top-5 products by revenue as an independent PHP calculation', function () {
    $expected = sortedByValue($this->oracle['2026-08'], -1);
    $expected = array_slice($expected, 0, 5);

    $rows = $this->repository->top('revenue', 'product', $this->august, 5);

    expect(array_map(fn ($r) => $r->entityId, $rows))->toBe($expected);
    foreach ($rows as $row) {
        expect($row->value)->toEqualWithDelta($this->oracle['2026-08'][$row->entityId], 1e-6);
    }
});

it('returns the same anti-top-5 as an independent PHP calculation', function () {
    $expected = array_slice(sortedByValue($this->oracle['2026-08'], 1), 0, 5);

    $rows = $this->repository->top('revenue', 'product', $this->august, 5, RankBy::Value, Direction::Asc);

    expect(array_map(fn ($r) => $r->entityId, $rows))->toBe($expected);
});

it('matches an independent month-over-month calculation for revenue of one month', function () {
    $aug = $this->oracle['2026-08'];
    $jul = $this->oracle['2026-07'];

    $rows = [];
    foreach ($this->repository->compare('revenue', 'product', $this->august, ComparisonBase::Previous) as $row) {
        $rows[$row->entityId] = $row;
    }

    expect(array_keys($rows))->toHaveCount(count($aug));
    foreach ($aug as $productId => $value) {
        $base = $jul[$productId] ?? null;
        $row = $rows[$productId];

        expect($row->value)->toEqualWithDelta($value, 1e-6)
            ->and($row->baseValue)->toEqualWithDelta($base, 1e-6);
        if ($base !== null && $base != 0.0) {
            expect($row->deltaAbs)->toEqualWithDelta($value - $base, 1e-6)
                ->and($row->deltaPct)->toEqualWithDelta(($value - $base) / $base * 100, 1e-6);
        }
    }
});

it('ranks by absolute month-over-month delta like an independent calculation', function () {
    $aug = $this->oracle['2026-08'];
    $jul = $this->oracle['2026-07'];
    $delta = [];
    foreach ($aug as $id => $v) {
        if (isset($jul[$id])) {
            $delta[$id] = round($v - $jul[$id], 4);
        }
    }

    $expected = array_slice(sortedByValue($delta, -1), 0, 5);
    $rows = $this->repository->top('revenue', 'product', $this->august, 5, RankBy::DeltaAbs, Direction::Desc, ComparisonBase::Previous);

    expect(array_map(fn ($r) => $r->entityId, $rows))->toBe($expected);
});
