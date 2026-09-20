<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\StockMovement;

/** Сделки месяца $month ('Y-m'), запрошенные диапазоном [$from; $to]; побайтовое представление. */
function monthDeals(MockAdapter $adapter, string $month, string $from, string $to): array
{
    $lines = [];
    foreach ($adapter->fetchDeals(new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($to))) as $deal) {
        if ($deal->date->format('Y-m') === $month) {
            $lines[] = serialize([$deal->id, $deal->productId, $deal->amount, $deal->date->format('Y-m-d H:i:s.u')]);
        }
    }

    return $lines;
}

/** @return array<string, array{string, string}> способ запроса => [from, to] для месяца $month */
function dealRequestsFor(MockAdapter $adapter, string $month): array
{
    $monthStart = new DateTimeImmutable($month.'-01');
    $quarterStart = $monthStart->modify('first day of -1 month');

    return [
        'month' => [$monthStart->format('Y-m-d'), $monthStart->format('Y-m-t')],
        'quarter' => [$quarterStart->format('Y-m-d'), $monthStart->modify('+1 month')->format('Y-m-t')],
        'history' => [$adapter->historyStart()->format('Y-m-d'), $adapter->historyEnd()->format('Y-m-d')],
        'wide' => ['2020-01-01', '2030-12-31'],
    ];
}

it('returns byte-identical deals of a month however the range is requested (Small)', function (string $month) {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $sets = [];
    foreach (dealRequestsFor($adapter, $month) as $name => [$from, $to]) {
        $sets[$name] = monthDeals($adapter, $month, $from, $to);
    }

    expect($sets['month'])->not->toBeEmpty()
        ->and($sets['quarter'])->toBe($sets['month'])
        ->and($sets['history'])->toBe($sets['month'])
        ->and($sets['wide'])->toBe($sets['month']);
})->with(['2025-09', '2025-12', '2026-06', '2026-08']);

it('returns byte-identical deals of a month however the range is requested (Medium)', function (string $month) {
    $adapter = new MockAdapter(MockDataProfile::Medium, 1);
    $sets = [];
    foreach (dealRequestsFor($adapter, $month) as $name => [$from, $to]) {
        $sets[$name] = monthDeals($adapter, $month, $from, $to);
    }

    expect($sets['month'])->toHaveCount(count($sets['wide']))
        ->and($sets['quarter'])->toBe($sets['month'])
        ->and($sets['history'])->toBe($sets['month'])
        ->and($sets['wide'])->toBe($sets['month']);
})->with(['2024-09', '2025-12', '2026-08'])->group('slow');

it('cuts a partial month by day without changing the deals of those days', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $whole = monthDeals($adapter, '2026-06', '2026-06-01', '2026-06-30');

    $days = [];
    foreach ($adapter->fetchDeals(new DateRange(new DateTimeImmutable('2026-06-10'), new DateTimeImmutable('2026-06-12'))) as $deal) {
        $days[] = serialize([$deal->id, $deal->productId, $deal->amount, $deal->date->format('Y-m-d H:i:s.u')]);
        expect($deal->date->format('Y-m-d'))->toBeGreaterThanOrEqual('2026-06-10')->toBeLessThanOrEqual('2026-06-12');
    }

    expect($days)->not->toBeEmpty()->and(array_diff($days, $whole))->toBe([]);
});

it('numbers deals by calendar month, independent of the request', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $all = iterator_to_array($adapter->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false);
    $ids = array_map(fn ($d) => $d->id, $all);

    expect(array_unique($ids))->toHaveCount(count($ids));

    $june = array_filter($all, fn ($d) => $d->date->format('Y-m') === '2026-06');
    $juneAlone = iterator_to_array($adapter->fetchDeals(new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'))), false);
    expect(array_map(fn ($d) => $d->id, $juneAlone))->toBe(array_map(fn ($d) => $d->id, array_values($june)));
});

it('is deterministic by seed: same seed — same deals, another seed — different', function () {
    $range = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'));
    $digest = fn (MockAdapter $a) => md5(serialize(array_map(fn ($d) => [$d->id, $d->productId, $d->amount, $d->date], iterator_to_array($a->fetchDeals($range), false))));

    expect($digest(new MockAdapter(MockDataProfile::Small, 7)))->toBe($digest(new MockAdapter(MockDataProfile::Small, 7)))
        ->and($digest(new MockAdapter(MockDataProfile::Small, 7)))->not->toBe($digest(new MockAdapter(MockDataProfile::Small, 8)));
});

it('returns the same movements and balances however the history is cut into ranges', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $key = fn (StockMovement $m) => serialize([$m->id, $m->productId, $m->warehouseId, $m->quantity, $m->type->value, $m->date->format('c')]);

    $whole = array_map($key, iterator_to_array($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false));
    $wide = array_map($key, iterator_to_array($adapter->fetchStockMovements(new DateRange(new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2030-12-31'))), false));

    $chunks = [];
    for ($month = new DateTimeImmutable('2025-09-01'); $month <= new DateTimeImmutable('2026-08-01'); $month = $month->modify('+1 month')) {
        foreach ($adapter->fetchStockMovements(new DateRange($month, $month->modify('last day of this month'))) as $m) {
            $chunks[] = $key($m);
        }
    }

    // Порядок «по товарам, внутри товара по времени» при нарезке по месяцам иной — сравниваем как множества.
    expect($wide)->toBe($whole)
        ->and(count($chunks))->toBe(count($whole))
        ->and(array_diff($chunks, $whole))->toBe([]);

    // Остаток на дату не зависит от того, чем запрошены движения: fetchStock(asOf) = сумма движений по диапазонам.
    $expected = [];
    foreach ($adapter->fetchStockMovements(new DateRange(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2026-03-15'))) as $m) {
        $expected[$m->productId.'|'.$m->warehouseId] = ($expected[$m->productId.'|'.$m->warehouseId] ?? 0.0) + $m->quantity;
    }
    foreach ($adapter->fetchStock(new DateTimeImmutable('2026-03-15')) as $balance) {
        expect($balance->quantity)->toEqualWithDelta($expected[$balance->productId.'|'.$balance->warehouseId] ?? 0.0, 1e-9);
    }
});
