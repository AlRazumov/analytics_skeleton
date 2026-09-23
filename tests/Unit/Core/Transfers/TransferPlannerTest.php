<?php

use App\Core\Transfers\StockSurplusDonor;
use App\Core\Transfers\TransferDonorReason;
use App\Core\Transfers\TransferPlan;
use App\Core\Transfers\TransferPlanner;
use App\Core\Transfers\TransferPosition;

/** deficit 14, target 30, keep 30, surplus 60, min 1 */
function tpPlanner(int $min = 1): TransferPlanner
{
    return new TransferPlanner(14, 30, 30, 60, $min);
}

function tpPos(string $product, string $wh, float $stock, float $rate): TransferPosition
{
    return new TransferPosition($product, $wh, $stock, $rate);
}

/** @return list<array{string, string, int}> */
function tpRows(TransferPlan $plan): array
{
    return array_map(fn ($r) => [$r->fromWarehouseId, $r->toWarehouseId, $r->quantity], $plan->recommendations);
}

it('moves exactly the need when the donor has more than enough', function () {
    // дефицит: 5 дней (stock 10, rate 2) → нужно 2*30-10 = 50; донор: 100 дней (stock 200, rate 2) → доступно 200-60 = 140
    $plan = tpPlanner()->plan([tpPos('p', 'A', 10, 2), tpPos('p', 'B', 200, 2)]);

    expect(tpRows($plan))->toBe([['B', 'A', 50]])
        ->and($plan->deficitPairs)->toBe(1)->and($plan->unmatchedDeficits)->toBe(0);
    $r = $plan->recommendations[0];
    expect($r->fromCoverageBefore)->toBe(100.0)->and($r->fromCoverageAfter)->toBe(75.0)
        ->and($r->toCoverageBefore)->toBe(5.0)->and($r->toCoverageAfter)->toBe(30.0)
        ->and($r->toDailyRate)->toBe(2.0)->and($r->productId)->toBe('p');
});

it('moves only what the donor can spare when it is less than the need', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 0, 5), tpPos('p', 'B', 130, 1)]);

    // получатель: нужно 5*30-0 = 150; донор: 130 дней, доступно 130-30 = 100
    expect(tpRows($plan))->toBe([['B', 'A', 100]]);
    expect($plan->recommendations[0]->fromCoverageAfter)->toBe(30.0);
});

it('keeps keep_days on the donor', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 0, 100), tpPos('p', 'B', 90, 1)]);

    expect($plan->recommendations[0]->quantity)->toBe(60)
        ->and($plan->recommendations[0]->fromCoverageAfter)->toBe(30.0);
});

it('uses several donors for one deficit, largest available first', function () {
    // нужно 10*30 = 300; доноры: B — доступно 100-30 = 70, C (500, rate 5) — доступно 500-150 = 350
    $plan = tpPlanner()->plan([tpPos('p', 'A', 0, 10), tpPos('p', 'B', 100, 1), tpPos('p', 'C', 500, 5)]);

    // C: min(300, 350) = 300 — хватает одного донора
    expect(tpRows($plan))->toBe([['C', 'A', 300]]);

    // потребность 20*30 = 600 больше, чем у самого крупного донора: C даёт 350, затем B — 70
    $plan = tpPlanner()->plan([tpPos('p', 'A', 0, 20), tpPos('p', 'B', 100, 1), tpPos('p', 'C', 500, 5)]);
    expect(tpRows($plan))->toBe([['B', 'A', 70], ['C', 'A', 350]])
        // нарастающий итог — в порядке распределения (C, затем B), вывод — по fromWarehouseId
        ->and($plan->recommendations[1]->toCoverageAfter)->toBe(350 / 20.0)
        ->and($plan->recommendations[0]->toCoverageAfter)->toBe(420 / 20.0);
});

it('serves the most urgent deficit first when one donor serves two', function () {
    // A: 2 дня (rate 5, stock 10) нужно 140; D: 10 дней (rate 5, stock 50) нужно 100; донор B: stock 1000, rate 5 → 200 дней, доступно 850 — хватит всем
    $plan = tpPlanner()->plan([tpPos('p', 'D', 50, 5), tpPos('p', 'A', 10, 5), tpPos('p', 'B', 1000, 5)]);
    expect(tpRows($plan))->toBe([['B', 'A', 140], ['B', 'D', 100]]);

    // Донор ограничен: stock 200, rate 1 → доступно 170; A получает 140, остаётся 30 для D (нужно 100)
    $plan = tpPlanner()->plan([tpPos('p', 'D', 50, 5), tpPos('p', 'A', 10, 5), tpPos('p', 'B', 200, 1)]);
    expect(tpRows($plan))->toBe([['B', 'A', 140], ['B', 'D', 30]])
        ->and($plan->recommendations[1]->fromCoverageAfter)->toBe(30.0);
});

it('counts a deficit without a donor as unmatched and returns no row for it', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 10, 5), tpPos('p', 'B', 100, 5), tpPos('q', 'A', 1, 1)]);

    expect($plan->recommendations)->toBe([])->and($plan->deficitPairs)->toBe(2)->and($plan->unmatchedDeficits)->toBe(2);
});

it('treats thresholds as inclusive: coverage == deficit_days is a deficit, == surplus_days is a donor', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 14, 1), tpPos('p', 'B', 60, 1)]);
    expect(tpRows($plan))->toBe([['B', 'A', 16]]);

    // чуть выше порога дефицита и чуть ниже порога донора — не участвуют
    $plan = tpPlanner()->plan([tpPos('p', 'A', 14.5, 1), tpPos('p', 'B', 59.5, 1)]);
    expect($plan->recommendations)->toBe([])->and($plan->deficitPairs)->toBe(0);
});

it('rounds quantity down and drops rows below min_quantity', function () {
    // rate 1, stock 1.4: нужно 30-1.4 = 28.6 → 28
    $plan = tpPlanner()->plan([tpPos('p', 'A', 1.4, 1), tpPos('p', 'B', 200, 1)]);
    expect(tpRows($plan))->toBe([['B', 'A', 28]]);

    // min_quantity 30 отбрасывает строку в 28 → дефицит без рекомендации
    $plan = tpPlanner(30)->plan([tpPos('p', 'A', 1.4, 1), tpPos('p', 'B', 200, 1)]);
    expect($plan->recommendations)->toBe([])->and($plan->unmatchedDeficits)->toBe(1);

    // отброшенная строка не тратит донора и считается как дефицит без рекомендации
    $plan = tpPlanner(20)->plan([tpPos('p', 'X', 13, 1), tpPos('p', 'Y', 0, 2), tpPos('p', 'B', 100, 1)]);
    expect(tpRows($plan))->toBe([['B', 'Y', 60]])->and($plan->unmatchedDeficits)->toBe(1)
        ->and($plan->deficitPairs)->toBe(2);
});

it('handles fractional inputs', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 2.5, 0.5), tpPos('p', 'B', 100.5, 1.25)]);

    // A: 5 дней, нужно 15-2.5 = 12.5 → 12; B: 80.4 дня, доступно 100.5-37.5 = 63
    expect(tpRows($plan))->toBe([['B', 'A', 12]])
        ->and($plan->recommendations[0]->toCoverageAfter)->toBe((2.5 + 12) / 0.5);
});

it('ignores non-positive rates and negative stock', function () {
    $plan = tpPlanner()->plan([
        tpPos('p', 'A', 0, 0), tpPos('p', 'B', 5, -1), tpPos('p', 'C', -3, 2), tpPos('p', 'D', 500, 0), tpPos('p', 'E', 10, 1), tpPos('p', 'F', 100, 1),
    ]);

    expect(tpRows($plan))->toBe([['F', 'E', 20]])->and($plan->deficitPairs)->toBe(1);
});

it('marks a normal donor with reason=turnover', function () {
    $plan = tpPlanner()->plan([tpPos('p', 'A', 10, 2), tpPos('p', 'B', 200, 2)]);

    expect($plan->recommendations[0]->reason)->toBe(TransferDonorReason::Turnover);
});

it('serves a deficit from a stock-surplus donor (no demand) and marks reason=stock_surplus', function () {
    // Дефицит: A (stock 10, rate 5) → 2 дня, нужно 5*30-10 = 140. Донор без спроса: 500 доступно.
    $plan = tpPlanner()->plan(
        [tpPos('p', 'A', 10, 5)],
        [new StockSurplusDonor('p', 'B', 500)],
    );

    expect(tpRows($plan))->toBe([['B', 'A', 140]]);
    $r = $plan->recommendations[0];
    expect($r->reason)->toBe(TransferDonorReason::StockSurplus)
        ->and($r->fromCoverageBefore)->toBe(INF)
        ->and($r->fromCoverageAfter)->toBe(INF)
        ->and($plan->deficitPairs)->toBe(1)->and($plan->unmatchedDeficits)->toBe(0);
});

it('splits a deficit between a turnover donor and a stock-surplus donor, largest available first', function () {
    // Нужно 5*30-10 = 140. Обычный донор доступен на 40 (B: stock 100, rate 1 → доступно 100-60=40).
    // Донор без спроса даёт остальные 100 (available=100, больше 40 → идёт первым).
    $plan = tpPlanner()->plan(
        [tpPos('p', 'A', 10, 5), tpPos('p', 'B', 100, 1)],
        [new StockSurplusDonor('p', 'C', 100)],
    );

    // Раздача идёт от донора без спроса (available 100 > 40), но итоговый порядок строк — по fromWarehouseId.
    expect(tpRows($plan))->toBe([['B', 'A', 40], ['C', 'A', 100]]);
    $byFrom = collect($plan->recommendations)->keyBy('fromWarehouseId');
    expect($byFrom['C']->reason)->toBe(TransferDonorReason::StockSurplus)
        ->and($byFrom['B']->reason)->toBe(TransferDonorReason::Turnover);
});

it('ignores a stock-surplus donor with available <= 0 and a product with no deficit', function () {
    $plan = tpPlanner()->plan(
        [tpPos('p', 'A', 1000, 1)],
        [new StockSurplusDonor('p', 'B', 0), new StockSurplusDonor('q', 'C', 50)],
    );

    expect($plan->recommendations)->toBe([])->and($plan->deficitPairs)->toBe(0);
});

it('validates the config', function (array $args) {
    expect(fn () => new TransferPlanner(...$args))->toThrow(InvalidArgumentException::class);
})->with([
    'deficit == target' => [[30, 30, 30, 60, 1]],
    'target > keep' => [[14, 40, 30, 60, 1]],
    'keep > surplus' => [[14, 30, 61, 60, 1]],
    'min 0' => [[14, 30, 30, 60, 0]],
    'min negative' => [[14, 30, 30, 60, -1]],
]);

it('accepts target == keep == surplus', function () {
    expect(new TransferPlanner(14, 60, 60, 60, 1))->toBeInstanceOf(TransferPlanner::class);
});

it('is deterministic for a shuffled input and orders the result', function () {
    $positions = [
        tpPos('p2', 'A', 10, 5), tpPos('p2', 'B', 1000, 5), tpPos('p2', 'C', 900, 5),
        tpPos('p1', 'A', 20, 5), tpPos('p1', 'B', 1000, 5), tpPos('p1', 'D', 5, 5),
        tpPos('p10', 'A', 0, 1), tpPos('p10', 'B', 100, 1),
    ];
    $expected = tpPlanner()->plan($positions);

    mt_srand(7);
    for ($i = 0; $i < 20; $i++) {
        shuffle($positions);
        expect(tpPlanner()->plan($positions))->toEqual($expected);
    }

    $before = array_map(fn ($r) => $r->toCoverageBefore, $expected->recommendations);
    $sorted = $before;
    sort($sorted);
    expect($before)->toBe($sorted)
        ->and(array_map(fn ($r) => $r->productId, $expected->recommendations)[0])->toBe('p10'); // покрытие 0
});

it('never breaks the invariants on 200 random sets', function () {
    mt_srand(20260920);
    $planner = new TransferPlanner(14, 30, 45, 60, 2);
    $eps = 1e-9;

    for ($set = 0; $set < 200; $set++) {
        $positions = [];
        for ($p = 0; $p < mt_rand(1, 4); $p++) {
            for ($w = 0; $w < mt_rand(1, 5); $w++) {
                $positions[] = tpPos("p{$p}", "w{$w}", mt_rand(-20, 4000) / 10, mt_rand(-5, 100) / 10);
            }
        }
        $byKey = [];
        foreach ($positions as $x) {
            $byKey[$x->productId.'|'.$x->warehouseId] = $x;
        }
        $positions = array_values($byKey);

        $plan = $planner->plan($positions);
        $given = [];
        $received = [];
        foreach ($plan->recommendations as $r) {
            $from = $byKey[$r->productId.'|'.$r->fromWarehouseId];
            $to = $byKey[$r->productId.'|'.$r->toWarehouseId];
            $given[$r->productId.'|'.$r->fromWarehouseId] = ($given[$r->productId.'|'.$r->fromWarehouseId] ?? 0) + $r->quantity;
            $received[$r->productId.'|'.$r->toWarehouseId] = ($received[$r->productId.'|'.$r->toWarehouseId] ?? 0) + $r->quantity;

            expect($r->quantity)->toBeGreaterThanOrEqual(2)
                ->and($r->fromWarehouseId)->not->toBe($r->toWarehouseId)
                ->and($r->fromCoverageAfter)->toBeGreaterThanOrEqual(45 - $eps)
                ->and($r->toCoverageAfter)->toBeLessThanOrEqual(30 + $eps)
                ->and($from->dailyRate)->toBeGreaterThan(0.0)->and($to->dailyRate)->toBeGreaterThan(0.0);
        }
        foreach ($given as $key => $sum) {
            $d = $byKey[$key];
            expect($sum)->toBeLessThanOrEqual($d->stock - $d->dailyRate * 45 + $eps)
                ->and(($d->stock - $sum) / $d->dailyRate)->toBeGreaterThanOrEqual(45 - $eps);
        }
        foreach ($received as $key => $sum) {
            $t = $byKey[$key];
            expect(($t->stock + $sum) / $t->dailyRate)->toBeLessThanOrEqual(30 + $eps);
        }
    }
});
