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

it('does not change the deals inside the window (reference from before the clamp)', function () {
    $small = dealsInWindow(new MockAdapter(MockDataProfile::Small, 1));
    $medium = dealsInWindow(new MockAdapter(MockDataProfile::Medium, 1));

    expect(count($small))->toBe(2660)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $small)), 2))->toBe(667594.43)
        ->and(dealsFingerprint($small))->toBe('14427abb8b8bd9a4079ca1327f5d4f42a5ff98e8076751ef160dbaa823020220')
        ->and(count($medium))->toBe(133000)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $medium)), 2))->toBe(33693948.73)
        ->and(dealsFingerprint($medium))->toBe('e972fecb7c8ddd9eca39bfb2b6038be258cac19537ec1f15fe31356d70f5450c');
})->group('slow');
