<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\StockMovementType;
use Psr\Log\AbstractLogger;

function wholeHistory(MockAdapter $adapter): DateRange
{
    $m = $adapter->manifest();

    return new DateRange(new DateTimeImmutable($m->historyStart), new DateTimeImmutable($m->historyEnd));
}

it('flags exactly the manifest dead products as dead stock at historyEnd', function (int $seed) {
    $adapter = new MockAdapter(MockDataProfile::Small, $seed);
    $manifest = $adapter->manifest();
    $records = (new DeadStockCalculator(90))->calculate($adapter, wholeHistory($adapter));

    $lastMonth = collect($records)->where('period', 'month:2026-08')->keyBy('entityId');
    $flagged = $lastMonth->filter(fn ($r) => $r->value >= 90)->keys()->sort()->values()->all();
    $dead = array_keys($manifest->deadProducts);
    sort($dead);

    expect($dead)->not->toBeEmpty();
    foreach ($dead as $id) {
        expect($lastMonth[$id]->value)->toBeGreaterThanOrEqual($manifest->deadDays)
            ->and($lastMonth[$id]->valueMeta['stock_qty'])->toBeGreaterThan(0.0);
    }
    // Ложных срабатываний на этих данных нет (см. отчёт); если появятся — список в сообщении.
    expect($flagged)->toBe($dead);
})->with([1, 2, 3]);

it('does not write dead stock rows for products without stock', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $records = (new DeadStockCalculator(90))->calculate($adapter, wholeHistory($adapter));
    $stock = [];
    foreach ($adapter->fetchStock() as $b) {
        $stock[$b->productId] = ($stock[$b->productId] ?? 0.0) + $b->quantity;
    }

    foreach (collect($records)->where('period', 'month:2026-08') as $r) {
        expect($stock[$r->entityId])->toBe($r->valueMeta['stock_qty']);
    }
});

it('gives days_of_stock equal to the manifest days_to_zero for near-zero products', function (MockDataProfile $profile) {
    $adapter = new MockAdapter($profile, 1);
    $manifest = $adapter->manifest();
    $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable($manifest->historyEnd));

    $records = collect((new DaysOfStockCalculator(28, 7))->calculate($adapter, $range))->keyBy('entityId');

    expect($manifest->nearZeroProducts)->not->toBeEmpty();
    foreach ($manifest->nearZeroProducts as $productId => $fact) {
        $record = $records[$productId.':'.$fact['warehouse_id']];

        // Допуск 1e-6: у этого сценария нет шума (ровно daily_rate в день,
        // сезонности нет), окно целиком с остатком — формула точна.
        expect($record->value)->toEqualWithDelta($fact['days_to_zero'], 1e-6)
            ->and($record->valueMeta['daily_rate'])->toEqualWithDelta($fact['daily_rate'], 1e-9)
            ->and($record->valueMeta['in_stock_days'])->toBe(28);
    }
})->with([MockDataProfile::Small, MockDataProfile::Medium]);

it('recovers the baseline sales rate of gap products exactly (zero-stock and arrival days excluded)', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $checked = 0;

    foreach ($adapter->manifest()->gapProducts as $productId => $windows) {
        foreach ($windows as $window) {
            // Окно 28 дней, заканчивающееся через 8 дней после конца провала: 20 дней провала
            // + день прихода + 7 рабочих дней. При +7 в окне только 6 дней с остатком на
            // начало дня (в день прихода он ещё 0) и метрика по правилу не пишется.
            $asOf = (new DateTimeImmutable($window['to']))->modify('+8 days');
            $range = new DateRange(new DateTimeImmutable($asOf->format('Y-m-01')), $asOf);
            $windowStart = $asOf->modify('-27 days');

            $sales = [];
            $baseline = null;
            foreach ($adapter->fetchStockMovements(new DateRange((new DateTimeImmutable($window['from']))->modify('-1 day'), $asOf)) as $m) {
                if ($m->productId !== $productId || $m->type !== StockMovementType::Sale) {
                    continue;
                }
                $day = $m->date->format('Y-m-d');
                $sales[$day] = ($sales[$day] ?? 0.0) - $m->quantity;
            }
            $baseline = $sales[(new DateTimeImmutable($window['from']))->modify('-1 day')->format('Y-m-d')] ?? null;
            // Продажи в окне метрики (с учётом того, что провал в нём есть).
            $inWindow = array_sum(array_filter($sales, fn ($_, $day) => $day >= $windowStart->format('Y-m-d'), ARRAY_FILTER_USE_BOTH));

            $record = collect((new DaysOfStockCalculator(28, 7))->calculate($adapter, $range))->firstWhere('entityId', $productId.':wh-1');

            $corrected = $record->valueMeta['daily_rate'];
            $naive = $inWindow / 28;

            expect($baseline)->not->toBeNull()
                ->and($record->valueMeta['in_stock_days'])->toBe(7)
                ->and($naive)->toBeLessThan($baseline * 0.5)                       // наивный сильно занижает
                ->and($corrected)->toEqualWithDelta($baseline, 1e-6);                 // продажи дня прихода в числитель не входят
            $checked++;
        }
    }

    expect($checked)->toBe(6); // 2 товара × 3 провала
});

it('skips the gap window metric when the window has fewer than min_in_stock_days days with stock', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $gaps = $adapter->manifest()->gapProducts;
    $productId = array_key_first($gaps);
    $windows = $gaps[$productId];
    $asOf = (new DateTimeImmutable($windows[0]['to']))->modify('+7 days');

    $calc = new DaysOfStockCalculator(28, 7);
    $records = collect($calc->calculate($adapter, new DateRange(new DateTimeImmutable($asOf->format('Y-m-01')), $asOf)));

    expect($records->firstWhere('entityId', $productId.':wh-1'))->toBeNull()
        ->and($calc->lastSkipped['too_few_in_stock_days'])->toBeGreaterThanOrEqual(1);
});

it('does not compute or fail stock metrics without the required capabilities and logs why', function (array $capabilities, bool $expectTurnover) {
    $logger = new class extends AbstractLogger
    {
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = [$level, (string) $message];
        }
    };
    $adapter = stubStockAdapter([stockMove('2026-01-05', 'p1', 'w1', StockMovementType::Sale, -1)], ['p1|w1' => 10.0], $capabilities);
    $range = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $records = (new MetricsCalculationService(logger: $logger))->calculate($adapter, $range);

    $keys = collect($records)->pluck('metricKey')->unique()->all();
    expect($keys)->not->toContain('days_since_last_sale')->not->toContain('days_of_stock')
        ->and(collect($logger->messages)->pluck(1)->implode(' '))->toContain('days_since_last_sale');
})->with([
    'no capabilities' => [[], false],
    'movements only' => [[AdapterCapability::StockMovements], true],
    'snapshots only' => [[AdapterCapability::StockSnapshots], false],
]);

it('computes both stock metrics through the service when capabilities are present', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

    $keys = collect((new MetricsCalculationService)->calculate($adapter, $range))->pluck('metricKey')->unique()->all();

    expect($keys)->toContain('days_since_last_sale')->toContain('days_of_stock')->toContain('turnover');
});
