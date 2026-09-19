<?php

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Фейковый DataSourceAdapter для unit-тестов core/Analytics: отдаёт
 * заранее заданные Deal/StockMovement независимо от переданного
 * периода — вызывающий уже отвечает за то, что передаёт диапазон,
 * фейку незачем его перепроверять.
 *
 * Считает количество вызовов fetchDeals()/fetchStockMovements()
 * ($fetchDealsCalls / $fetchStockMovementsCalls) — используется в
 * MetricsCalculationServiceTest как регрессионный тест против
 * повторных вызовов адаптера из нескольких калькуляторов (см.
 * docs/reports/stage-04-report.md, раздел "Наблюдение: контракт «один
 * вызов fetch* за прогон» не соблюдается буквально").
 */
function fakeAdapter(array $deals = [], array $stockMovements = [], ?array $capabilities = null): DataSourceAdapter
{
    return new class($deals, $stockMovements, $capabilities ?? [AdapterCapability::StockMovements, AdapterCapability::StockSnapshots]) implements DataSourceAdapter
    {
        public int $fetchDealsCalls = 0;

        public int $fetchStockMovementsCalls = 0;

        public int $fetchStockCalls = 0;

        public function __construct(
            private array $deals,
            private array $stockMovements,
            private array $capabilities,
        ) {}

        public function fetchDeals(DateRange $period): iterable
        {
            $this->fetchDealsCalls++;

            return $this->deals;
        }

        public function fetchProducts(): iterable
        {
            return [];
        }

        public function fetchWarehouses(): iterable
        {
            return [];
        }

        public function fetchStockMovements(DateRange $period): iterable
        {
            $this->fetchStockMovementsCalls++;

            return $this->stockMovements;
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            $this->fetchStockCalls++;

            return [];
        }

        public function capabilities(): array
        {
            return $this->capabilities;
        }
    };
}

/**
 * Адаптер с заданными движениями и начальными остатками ($opening:
 * "productId|warehouseId" => qty на момент ДО первого движения).
 * fetchStock(asOf) = opening + движения до конца дня asOf — как у
 * настоящего источника; fetchStockMovements фильтрует по датам включительно.
 */
function stubStockAdapter(array $movements, array $opening = [], ?array $capabilities = null): DataSourceAdapter
{
    return new class($movements, $opening, $capabilities ?? [AdapterCapability::StockMovements, AdapterCapability::StockSnapshots]) implements DataSourceAdapter
    {
        public function __construct(private array $movements, private array $opening, private array $capabilities) {}

        public function fetchDeals(DateRange $period): iterable
        {
            return [];
        }

        public function fetchProducts(): iterable
        {
            return [];
        }

        public function fetchWarehouses(): iterable
        {
            return [];
        }

        public function fetchStockMovements(DateRange $period): iterable
        {
            $from = $period->start->format('Y-m-d');
            $to = $period->end->format('Y-m-d');

            foreach ($this->movements as $m) {
                $day = $m->date->format('Y-m-d');
                if ($day >= $from && $day <= $to) {
                    yield $m;
                }
            }
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            $balances = $this->opening;
            foreach ($this->movements as $m) {
                if ($asOf === null || $m->date->format('Y-m-d') <= $asOf->format('Y-m-d')) {
                    $key = $m->productId.'|'.$m->warehouseId;
                    $balances[$key] = ($balances[$key] ?? 0.0) + $m->quantity;
                }
            }

            foreach ($balances as $key => $quantity) {
                [$productId, $warehouseId] = explode('|', $key);
                yield new StockBalance($productId, $warehouseId, (float) $quantity);
            }
        }

        public function capabilities(): array
        {
            return $this->capabilities;
        }
    };
}

/** Движение для тестов: тип определяет знак по $quantity (передавайте со знаком). */
function stockMove(string $day, string $productId, string $warehouseId, StockMovementType $type, float $quantity): StockMovement
{
    static $n = 0;

    return new StockMovement('t-'.++$n, $productId, $warehouseId, $quantity, $type, new DateTimeImmutable($day.' 12:00'));
}

/** Адаптер, падающий на любом fetch*: веб-запросы страниц к источнику обращаться не должны. */
function throwingAdapter(): DataSourceAdapter
{
    return new class implements DataSourceAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            throw new RuntimeException('adapter used: fetchDeals');
        }

        public function fetchProducts(): iterable
        {
            throw new RuntimeException('adapter used: fetchProducts');
        }

        public function fetchWarehouses(): iterable
        {
            throw new RuntimeException('adapter used: fetchWarehouses');
        }

        public function fetchStockMovements(DateRange $period): iterable
        {
            throw new RuntimeException('adapter used: fetchStockMovements');
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            throw new RuntimeException('adapter used: fetchStock');
        }

        public function capabilities(): array
        {
            return [];
        }
    };
}
