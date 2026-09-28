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

// Эталон переснят на этапе 30 (docs/reports/stage-30-consistent-mock.md):
// сделки больше не генерируются отдельно — каждая продажа со склада даёт
// сделку «штуки × цена товара», возвраты — сделки с минусом. Число, сумма и
// состав сделок меняются преднамеренно; согласованность с движениями
// проверяет MockConsistencyTest. Было (этап 24): Small 2712 / 681782.05 /
// ac7c8311…609e94cd, Medium 133233 / 33585617.71 / bab3b5e0…8a191b72.
it('matches the reference fingerprint of the deals inside the window', function () {
    $small = dealsInWindow(new MockAdapter(MockDataProfile::Small, 1));
    $medium = dealsInWindow(new MockAdapter(MockDataProfile::Medium, 1));

    expect(count($small))->toBe(12882)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $small)), 2))->toBe(13011698.01)
        ->and(dealsFingerprint($small))->toBe('c1a279471743f6d0a1ee0a7651b5970f35d9eb61ef82be29fa8baf36c180ba1d')
        ->and(count($medium))->toBe(229293)
        ->and(round(array_sum(array_map(fn ($d) => $d->amount, $medium)), 2))->toBe(234987286.48)
        ->and(dealsFingerprint($medium))->toBe('b18e2d541cd8e1ec0cf525687c057ecf808e9fd5933a58c5ede73f1ed803d2e5');
})->group('slow');
