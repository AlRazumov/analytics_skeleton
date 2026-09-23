<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;

function dealsInWindow(MockAdapter $adapter): array
{
    return iterator_to_array($adapter->fetchDeals(
        new DateRange($adapter->historyStart(), $adapter->historyEnd()->setTime(23, 59, 59))
    ), false);
}

function dealsFingerprint(array $deals): string
{
    return hash('sha256', implode("\n", array_map(
        fn ($d) => $d->id.'|'.$d->date->format('Y-m-d H:i:s').'|'.number_format($d->amount, 2, '.', '').'|'.$d->productId,
        $deals,
    )));
}

it('does not return deals outside the history window', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $wide = new DateRange(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2028-12-31'));

    $deals = iterator_to_array($adapter->fetchDeals($wide), false);
    $dates = array_map(fn ($d) => $d->date->format('Y-m-d'), $deals);

    expect($deals)->not->toBeEmpty()
        ->and(min($dates))->toBeGreaterThanOrEqual($adapter->historyStart()->format('Y-m-d'))
        ->and(max($dates))->toBeLessThanOrEqual($adapter->historyEnd()->format('Y-m-d'));
});

it('returns no deals for a period entirely before or after the window', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);

    $before = new DateRange(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31'));
    $after = new DateRange(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-31'));

    expect(iterator_to_array($adapter->fetchDeals($before), false))->toBeEmpty()
        ->and(iterator_to_array($adapter->fetchDeals($after), false))->toBeEmpty();
});

it('keeps deals on the first and last day of the window', function () {
    foreach ([MockDataProfile::Small, MockDataProfile::Medium] as $profile) {
        $adapter = new MockAdapter($profile, 1);
        $dates = array_map(fn ($d) => $d->date->format('Y-m-d'), dealsInWindow($adapter));

        expect(min($dates))->toBe($adapter->historyStart()->format('Y-m-d'))
            ->and(max($dates))->toBe($adapter->historyEnd()->format('Y-m-d'));
    }
});

// Эталон переснят после добавления сценария «потерянные продажи»
// (LostSalesCalculator, docs/reports/lost-sales-and-stock-surplus-donor.md):
// у lost_sales-товаров теперь гарантированная сделка в каждом месяце окна
// истории, кроме последнего (там обычные случайные попадания на них гасятся) —
// это преднамеренно меняет число/сумму/состав сделок. Было (этап 13):
// Small 2660 / 673591.97 / 19b9dfb7…0284b9, Medium 133000 / 33558933.48 / cc47982e…fac1063.
it('matches the reference fingerprint of the deals inside the window', function () {
    $small = dealsInWindow(new MockAdapter(MockDataProfile::Small, 1));
    $medium = dealsInWindow(new MockAdapter(MockDataProfile::Medium, 1));

    expect(count($small))->toBe(2678)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $small)), 2))->toBe(677501.92)
        ->and(dealsFingerprint($small))->toBe('92dc1ed51774cdeb07808198ef9493b2c8cfe1e8aa4d1a3f269ec40ef0228640')
        ->and(count($medium))->toBe(133063)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $medium)), 2))->toBe(33571544.56)
        ->and(dealsFingerprint($medium))->toBe('a1f696c99754886f972182cf44765e687abc8c1c75cf062bbf625c9896b16862');
})->group('slow');
