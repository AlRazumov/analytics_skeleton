<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\Sellers\SellerMetricsCalculator;
use App\Core\Analytics\Sellers\SellerSalesData;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\SellerCoverage;

function sellerDeal(string $id, ?string $seller, float $amount, string $day): Deal
{
    return new Deal($id, 'p1', $amount, new DateTimeImmutable($day), [], $seller);
}

/** @return array<string, array<string, float>> metric_key => entity_id => value за один месяц */
function sellerValues(array $deals, string $period): array
{
    $out = [];
    foreach ((new SellerMetricsCalculator(SellerMetricsCalculator::builtIn()))->calculate($deals, SellerCoverage::Full) as $r) {
        if ($r->period === $period) {
            $out[$r->metricKey][$r->entityId] = $r->value;
        }
    }

    return $out;
}

it('keeps sum over sellers + no-seller equal to the total, for count and amount', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 42);
    $range = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'));
    $deals = iterator_to_array($adapter->fetchDeals($range), false);

    $records = (new SellerMetricsCalculator(SellerMetricsCalculator::builtIn()))->calculate($deals, SellerCoverage::Full);

    foreach (['2026-06', '2026-07', '2026-08'] as $month) {
        $inMonth = array_filter($deals, fn (Deal $d) => $d->date->format('Y-m') === $month);
        $count = array_sum(array_map(fn ($r) => $r->value, array_filter($records, fn ($r) => $r->metricKey === 'sales_count' && $r->period === "month:$month")));
        $amount = array_sum(array_map(fn ($r) => $r->value, array_filter($records, fn ($r) => $r->metricKey === 'sales_amount' && $r->period === "month:$month")));
        $share = array_sum(array_map(fn ($r) => $r->value, array_filter($records, fn ($r) => $r->metricKey === 'share_of_total' && $r->period === "month:$month")));

        expect($count)->toBe((float) count($inMonth))
            ->and($amount)->toEqualWithDelta(array_sum(array_map(fn (Deal $d) => $d->amount, $inMonth)), 0.01)
            ->and($share)->toEqualWithDelta(100.0, 0.001);
    }

    $noSeller = array_filter($records, fn ($r) => $r->entityId === SellerSalesData::NO_SELLER && $r->metricKey === 'sales_count');
    expect($noSeller)->not->toBeEmpty();
});

it('puts a seller-less deal under the reserved key and never loses it', function () {
    $values = sellerValues([
        sellerDeal('1', 'a', 10, '2026-08-03'),
        sellerDeal('2', null, 5, '2026-08-04'),
    ], 'month:2026-08');

    expect($values['sales_count'])->toBe(['a' => 1.0, SellerSalesData::NO_SELLER => 1.0])
        ->and($values['sales_amount'][SellerSalesData::NO_SELLER])->toBe(5.0);
});

it('normalises sales per active day for a newcomer and for a fired seller', function () {
    $deals = [
        // новичок: в августе первая продажа 10-го, последняя 20-го → окно 11 дней
        sellerDeal('1', 'new', 1, '2026-08-10'), sellerDeal('2', 'new', 1, '2026-08-12'),
        sellerDeal('3', 'new', 1, '2026-08-15'), sellerDeal('4', 'new', 1, '2026-08-20'),
        // уволенный: продавал с начала июля до 10-го → окно 10 дней (июль усечён последней продажей)
        sellerDeal('5', 'gone', 1, '2026-07-01'), sellerDeal('6', 'gone', 1, '2026-07-05'),
        sellerDeal('7', 'gone', 1, '2026-07-10'),
    ];

    expect(sellerValues($deals, 'month:2026-08')['sales_per_active_day']['new'])->toBe(4 / 11)
        ->and(sellerValues($deals, 'month:2026-07')['sales_per_active_day']['gone'])->toBe(3 / 10)
        ->and(sellerValues($deals, 'month:2026-08')['sales_per_active_day'])->not->toHaveKey('gone');
});

it('computes trend against the previous month and skips sellers without a base', function () {
    $values = sellerValues([
        sellerDeal('1', 'a', 1, '2026-07-01'), sellerDeal('2', 'a', 1, '2026-07-02'),
        sellerDeal('3', 'a', 1, '2026-08-01'), sellerDeal('4', 'a', 1, '2026-08-02'), sellerDeal('5', 'a', 1, '2026-08-03'),
        sellerDeal('6', 'b', 1, '2026-08-04'),
    ], 'month:2026-08');

    expect($values['trend']['a'])->toBe(50.0)->and($values['trend'])->not->toHaveKey('b');
});

it('gives the mock a deterministic roster with a fired seller, a newcomer and unassigned deals', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 42);
    $sellers = iterator_to_array($adapter->fetchSellers(), false);
    $deals = iterator_to_array($adapter->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false);

    expect($sellers)->toHaveCount(12)
        ->and(array_values(array_filter($sellers, fn ($s) => ! $s->isActive)))->toHaveCount(1)
        ->and(array_unique(array_map(fn ($s) => $s->branchId, $sellers)))->not->toBeEmpty();

    $byId = [];
    foreach ($deals as $d) {
        $byId[$d->sellerId ?? 'none'][] = $d->date->format('Y-m-d');
    }
    $none = count($byId['none']) / count($deals);
    $top3 = ($count = fn (string $id) => count($byId[$id] ?? []))('seller-1') + $count('seller-2') + $count('seller-3');
    $fired = array_values(array_filter($sellers, fn ($s) => ! $s->isActive))[0]->id;
    $half = $adapter->historyStart()->modify('+183 days')->format('Y-m-d');

    expect($none)->toBeBetween(0.05, 0.10)
        ->and($top3 / count($deals))->toBeBetween(0.45, 0.60)
        ->and(max($byId[$fired]))->toBeLessThan($half)
        ->and(min($byId['seller-8']))->toBeGreaterThanOrEqual($adapter->historyEnd()->modify('-28 days')->format('Y-m-d'));

    $again = iterator_to_array((new MockAdapter(MockDataProfile::Small, 42))->fetchDeals(new DateRange($adapter->historyStart(), $adapter->historyEnd())), false);
    expect(array_map(fn ($d) => $d->sellerId, $again))->toBe(array_map(fn ($d) => $d->sellerId, $deals));
});

it('emulates the three seller-on-deal modes in the mock', function () {
    $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
    $share = function (SellerCoverage $mode) use ($range) {
        $adapter = new MockAdapter(MockDataProfile::Small, 42, sellerCoverage: $mode);
        $deals = iterator_to_array($adapter->fetchDeals($range), false);

        return [count(array_filter($deals, fn ($d) => $d->sellerId === null)) / count($deals), iterator_to_array($adapter->fetchSellers(), false)];
    };

    [$full] = $share(SellerCoverage::Full);
    [$partial] = $share(SellerCoverage::Partial);
    [$none, $noneSellers] = $share(SellerCoverage::None);

    expect($full)->toBeLessThan(0.15)->and($partial)->toBeBetween(0.25, 0.45)->and($none)->toEqual(1)->and($noneSellers)->toBe([]);
});

it('does not touch the product, amount and date stream of the mock when sellers change', function () {
    $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
    $stream = fn (SellerCoverage $mode) => array_map(
        fn (Deal $d) => [$d->id, $d->productId, $d->amount, $d->date->format('c')],
        iterator_to_array((new MockAdapter(MockDataProfile::Small, 42, sellerCoverage: $mode))->fetchDeals($range), false),
    );

    expect($stream(SellerCoverage::Full))->toBe($stream(SellerCoverage::None))->toBe($stream(SellerCoverage::Partial));
});
