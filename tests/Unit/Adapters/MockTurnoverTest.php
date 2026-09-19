<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\TurnoverCalculator;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

function turnoverAdapter(MockDataProfile $profile): MockAdapter
{
    static $adapters = [];

    return $adapters[$profile->value] ??= new MockAdapter($profile, 1);
}

/** @return array<string, float> productId → суммарный остаток по складам на конец дня */
function turnoverStock(MockAdapter $adapter, string $ymd): array
{
    $stock = [];
    foreach ($adapter->fetchStock(new DateTimeImmutable($ymd)) as $b) {
        $stock[$b->productId] = ($stock[$b->productId] ?? 0.0) + $b->quantity;
    }

    return $stock;
}

/** Как метрику считает сервис: стартовый остаток = fetchStock(начало диапазона − 1 день). */
function turnoverRows(MockAdapter $adapter, string $from, string $to): array
{
    // Мок детерминирован, а расчёт на Medium дорог — одинаковые вызовы не повторяем.
    static $cache = [];
    $key = spl_object_id($adapter).$from.$to;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $range = new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($to));
    $opening = turnoverStock($adapter, (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));

    return $cache[$key] = (new TurnoverCalculator)->calculate($adapter->fetchStockMovements($range), $range, $opening);
}

/** @return array<string, float> productId → значение месяца */
function turnoverOfMonth(array $records, string $month): array
{
    $rows = [];
    /** @var MetricsSnapshotRecord $r */
    foreach ($records as $r) {
        if ($r->period === 'month:'.$month) {
            $rows[$r->entityId] = $r->value;
        }
    }
    ksort($rows);

    return $rows;
}

/** Независимый эталон: продано / ((fetchStock(конец пред. месяца) + fetchStock(конец месяца)) / 2). */
function turnoverOracle(MockAdapter $adapter, string $month): array
{
    $start = new DateTimeImmutable($month.'-01');
    $end = $start->modify('last day of this month');
    $open = turnoverStock($adapter, $start->modify('-1 day')->format('Y-m-d'));
    $close = turnoverStock($adapter, $end->format('Y-m-d'));

    $sold = [];
    foreach ($adapter->fetchStockMovements(new DateRange($start, $end)) as $m) {
        if ($m->type === StockMovementType::Sale) {
            $sold[$m->productId] = ($sold[$m->productId] ?? 0.0) - $m->quantity;
        }
    }

    $rows = [];
    foreach (array_unique([...array_keys($open), ...array_keys($close), ...array_keys($sold)]) as $id) {
        $avg = (($open[$id] ?? 0.0) + ($close[$id] ?? 0.0)) / 2;
        if ($avg > 0.0) {
            $rows[$id] = ($sold[$id] ?? 0.0) / $avg;
        }
    }
    ksort($rows);

    return $rows;
}

function expectSameTurnover(array $actual, array $expected): void
{
    expect(array_keys($actual))->toBe(array_keys($expected));
    foreach ($expected as $id => $value) {
        expect($actual[$id])->toEqualWithDelta($value, 1e-9);
    }
}

$gives_the_same_august_tu = function (MockDataProfile $profile) {
    $adapter = turnoverAdapter($profile);

    $oracle = turnoverOracle($adapter, '2026-08');
    $alone = turnoverOfMonth(turnoverRows($adapter, '2026-08-01', '2026-08-31'), '2026-08');
    $year = turnoverOfMonth(turnoverRows($adapter, '2025-09-01', '2026-08-31'), '2026-08');

    expect($oracle)->not->toBeEmpty();
    expectSameTurnover($alone, $oracle);
    expectSameTurnover($year, $oracle);
};

it('gives the same August turnover whether calculated for August only or for the whole year, equal to the fetchStock oracle', $gives_the_same_august_tu)->with([MockDataProfile::Small]);

// Medium-профиль дорог (секунды) — группа slow, см. README.
it('gives the same August turnover whether calculated for August only or for the whole year, equal to the fetchStock oracle (Medium)', $gives_the_same_august_tu)->with([MockDataProfile::Medium])->group('slow');

it('is correct on Medium with the default command period (12 of 24 history months) for sampled months', function () {
    $adapter = turnoverAdapter(MockDataProfile::Medium);
    $end = $adapter->historyEnd();
    $from = (new DateTimeImmutable($end->format('Y-m-01')))->modify('-11 months')->format('Y-m-d');

    $records = turnoverRows($adapter, $from, $end->format('Y-m-d'));

    expect($from)->toBe('2025-09-01');
    foreach (['2025-09', '2026-01', '2026-08'] as $month) {
        expectSameTurnover(turnoverOfMonth($records, $month), turnoverOracle($adapter, $month));
    }
})->group('slow');
