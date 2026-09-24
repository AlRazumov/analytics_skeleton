<?php

use App\Adapters\Contract\AdapterContractChecker;
use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Contracts\ProvidesHistoryBounds;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\Product;
use App\Core\Domain\Seller;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use Tests\Support\InMemoryAdapter;

/** Последние три месяца истории мока — диапазон, где есть все виды данных. */
function mockProbeRange(MockAdapter $adapter): DateRange
{
    $end = $adapter->historyEnd();

    return new DateRange((new DateTimeImmutable($end->format('Y-m-01')))->modify('-2 months'), $end);
}

function julyRange(): DateRange
{
    return new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-31'));
}

it('passes MockAdapter on Small for every seller coverage mode', function (SellerCoverage $coverage) {
    $adapter = new MockAdapter(MockDataProfile::Small, 42, sellerCoverage: $coverage);

    $report = (new AdapterContractChecker)->check($adapter, mockProbeRange($adapter));

    expect($report->violations)->toBe([])
        ->and($report->counts['deals'])->toBeGreaterThan(0)
        ->and($report->counts['movements'])->toBeGreaterThan(0)
        ->and($report->counts['balances'])->toBeGreaterThan(0)
        ->and($report->skipped)->toBe([]);
})->with([SellerCoverage::Full, SellerCoverage::Partial, SellerCoverage::None]);

it('passes MockAdapter on Medium', function () {
    $adapter = new MockAdapter(MockDataProfile::Medium, 7);

    expect((new AdapterContractChecker)->check($adapter, mockProbeRange($adapter))->violations)->toBe([]);
})->group('slow');

it('passes the in-memory reference adapter and skips only the history bounds', function (SellerCoverage $coverage) {
    $report = (new AdapterContractChecker)->check(new InMemoryAdapter($coverage), julyRange());

    expect($report->violations)->toBe([])
        ->and($report->counts)->toBe(['deals' => 5, 'products' => 2, 'warehouses' => 2, 'sellers' => $coverage === SellerCoverage::None ? 0 : 2, 'movements' => 10, 'balances' => 4])
        ->and($report->skipped)->toHaveCount(1)
        ->and($report->skipped[0])->toStartWith('history.bounds');
})->with([SellerCoverage::Full, SellerCoverage::Partial, SellerCoverage::None]);

it('accepts deals without a seller under full coverage (like the mock does)', function () {
    $a = new InMemoryAdapter(SellerCoverage::Full);
    $a->deals[] = new Deal('x1', 'p1', 1.0, new DateTimeImmutable('2026-07-05'));

    expect((new AdapterContractChecker)->check($a, julyRange())->violations)->toBe([]);
});

it('skips stock rules without the capabilities instead of calling those methods', function () {
    $adapter = new class(SellerCoverage::Full, []) extends InMemoryAdapter
    {
        public function fetchStockMovements(DateRange $period): iterable
        {
            throw new LogicException('must not be called');
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            throw new LogicException('must not be called');
        }
    };

    $report = (new AdapterContractChecker)->check($adapter, julyRange());

    expect($report->violations)->toBe([])
        ->and(implode("\n", $report->skipped))->toContain('movements.*')->toContain('stock.*');
});

it('catches a broken adapter by the expected rule', function (Closure $make, string $rule) {
    $report = (new AdapterContractChecker)->check($make(), julyRange());

    expect($report->passed())->toBeFalse()->and($report->rules())->toContain($rule);
})->with([
    'deals ignore the range' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            return $this->deals;
        }
    }, 'deals.range'],
    'duplicate deal id' => [function () {
        $a = new InMemoryAdapter;
        $a->deals[] = new Deal('d7', 'p1', 1.0, new DateTimeImmutable('2026-07-05'));

        return $a;
    }, 'deals.ids'],
    'deal of an unknown product' => [function () {
        $a = new InMemoryAdapter;
        $a->deals[] = new Deal('x1', 'p404', 1.0, new DateTimeImmutable('2026-07-05'));

        return $a;
    }, 'deals.products'],
    'NaN amount' => [function () {
        $a = new InMemoryAdapter;
        $a->deals[] = new Deal('x1', 'p1', NAN, new DateTimeImmutable('2026-07-05'));

        return $a;
    }, 'deals.amount'],
    'not a Deal' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            return [...parent::fetchDeals($period), ['id' => 'raw']];
        }
    }, 'deals.type'],
    'different deals on every call' => [fn () => new class extends InMemoryAdapter
    {
        private int $calls = 0;

        public function fetchDeals(DateRange $period): iterable
        {
            $this->calls++;

            return array_map(fn (Deal $d) => new Deal($d->id, $d->productId, $d->amount + $this->calls, $d->date, sellerId: $d->sellerId), parent::fetchDeals($period));
        }
    }, 'deals.repeatable'],
    'range end treated as exclusive' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            return array_filter(parent::fetchDeals($period), fn (Deal $d) => $d->date->format('Y-m-d') < $period->end->format('Y-m-d'));
        }
    }, 'deals.split'],
    'full coverage but no deal has a seller' => [function () {
        $a = new InMemoryAdapter(SellerCoverage::None);
        $a->coverage = SellerCoverage::Full;

        return $a;
    }, 'sellers.coverage'],
    'none coverage with a seller on a deal' => [function () {
        $a = new InMemoryAdapter(SellerCoverage::None);
        $a->sellers = [new Seller('s1', 'Иванов')];
        $a->deals[] = new Deal('x1', 'p1', 1.0, new DateTimeImmutable('2026-07-05'), sellerId: 's1');

        return $a;
    }, 'sellers.coverage'],
    'empty seller id instead of null' => [function () {
        $a = new InMemoryAdapter;
        $a->deals[] = new Deal('x1', 'p1', 1.0, new DateTimeImmutable('2026-07-05'), sellerId: '');

        return $a;
    }, 'sellers.on_deals'],
    'unknown seller on a deal' => [function () {
        $a = new InMemoryAdapter;
        $a->deals[] = new Deal('x1', 'p1', 1.0, new DateTimeImmutable('2026-07-05'), sellerId: 's404');

        return $a;
    }, 'sellers.on_deals'],
    'duplicate product id' => [function () {
        $a = new InMemoryAdapter;
        $a->products[] = new Product('p1', 'Болт (копия)');

        return $a;
    }, 'products.ids'],
    'empty product name' => [function () {
        $a = new InMemoryAdapter;
        $a->products[] = new Product('p3', ' ');

        return $a;
    }, 'products.names'],
    'duplicate capability' => [fn () => new InMemoryAdapter(capabilities: [AdapterCapability::StockMovements, AdapterCapability::StockMovements]), 'capabilities'],
    'movements ignore the range' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchStockMovements(DateRange $period): iterable
        {
            return $this->movements;
        }
    }, 'movements.range'],
    'movement on an unknown warehouse' => [function () {
        $a = new InMemoryAdapter;
        $a->movements[] = new StockMovement('x1', 'p1', 'w404', 1.0, StockMovementType::Receipt, new DateTimeImmutable('2026-07-05'));

        return $a;
    }, 'movements.warehouses'],
    'stock ignores asOf' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            return parent::fetchStock(null);
        }
    }, 'stock.movements_balance'],
    'stock depends on the time of day' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            // «Остаток на момент», а не на конец дня: движения того же дня позже asOf не учитываются.
            $asOf = $asOf?->format('H:i:s') === '00:00:00' ? $asOf->modify('-1 day') : $asOf;

            return parent::fetchStock($asOf);
        }
    }, 'stock.time_ignored'],
    'duplicate stock pair' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            return [...parent::fetchStock($asOf), new StockBalance('p1', 'w1', 0.0)];
        }
    }, 'stock.pairs'],
    'fetchDeals throws' => [fn () => new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            throw new RuntimeException('HTTP 503');
        }
    }, 'deals.error'],
    'data after historyEnd' => [fn () => new class extends InMemoryAdapter implements ProvidesHistoryBounds
    {
        public function historyEnd(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-07-15');
        }
    }, 'history.bounds'],
]);

it('keeps checking other sections after a method throws', function () {
    $adapter = new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            throw new RuntimeException('HTTP 503');
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            return parent::fetchStock(null);
        }
    };

    $report = (new AdapterContractChecker)->check($adapter, julyRange());

    expect($report->rules())->toBe(['deals.error', 'stock.movements_balance'])
        ->and($report->violations[0]->examples)->toBe(['RuntimeException: HTTP 503']);
});

it('counts every case but keeps at most MAX_EXAMPLES examples', function () {
    $adapter = new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            return $this->deals;
        }
    };

    $violation = collect((new AdapterContractChecker)->check($adapter, julyRange())->violations)->firstWhere('rule', 'deals.range');

    expect($violation->count)->toBe(10) // 15 сделок, 5 из них в июле
        ->and($violation->examples)->toHaveCount(AdapterContractChecker::MAX_EXAMPLES);
});
