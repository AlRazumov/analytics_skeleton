<?php

namespace Tests\Support;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\Product;
use App\Core\Domain\Seller;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use App\Core\Domain\Warehouse;
use DateTimeImmutable;

/**
 * Эталонный (соблюдающий контракт) адаптер на массивах — для тестов
 * AdapterContractChecker. Сломанные варианты — анонимные подклассы,
 * переопределяющие один метод. Данные по умолчанию: 2 товара, 2 склада,
 * 2 продавца, сделки и движения с июня по август 2026.
 */
class InMemoryAdapter implements DataSourceAdapter
{
    /** @var list<Product> */
    public array $products;

    /** @var list<Warehouse> */
    public array $warehouses;

    /** @var list<Seller> */
    public array $sellers;

    /** @var list<Deal> */
    public array $deals;

    /** @var list<StockMovement> */
    public array $movements;

    /** @var array<string, float> "product|warehouse" => остаток до первого движения */
    public array $opening = ['p1|w1' => 10.0, 'p1|w2' => 5.0, 'p2|w1' => 0.0, 'p2|w2' => 3.0];

    /**
     * @param  list<AdapterCapability>  $capabilities
     */
    public function __construct(
        public SellerCoverage $coverage = SellerCoverage::Partial,
        public array $capabilities = [AdapterCapability::StockMovements, AdapterCapability::StockSnapshots],
    ) {
        $this->products = [new Product('p1', 'Болт'), new Product('p2', 'Гайка')];
        $this->warehouses = [new Warehouse('w1', 'Центральный'), new Warehouse('w2', 'Северный')];
        $this->sellers = $coverage === SellerCoverage::None ? [] : [new Seller('s1', 'Иванов'), new Seller('s2', 'Петров')];

        $this->deals = [];
        $this->movements = [];
        $n = 0;
        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            foreach ([3, 10, 17, 24, 31] as $i => $dayOfMonth) {
                $day = new DateTimeImmutable(sprintf('%s-%02d', $month, min($dayOfMonth, (int) date('t', strtotime("{$month}-01")))));
                $n++;
                $seller = match ($coverage) {
                    SellerCoverage::None => null,
                    SellerCoverage::Full => $i % 2 === 0 ? 's1' : 's2',
                    SellerCoverage::Partial => [null, 's1', 's2'][$i % 3],
                };
                $product = $i % 2 === 0 ? 'p1' : 'p2';
                $warehouse = $i % 3 === 0 ? 'w1' : 'w2';
                $this->deals[] = new Deal("d{$n}", $product, 100.0 + $n, $day->setTime(12, 0), sellerId: $seller);
                $this->movements[] = new StockMovement("m{$n}-in", $product, $warehouse, 2.0, StockMovementType::Receipt, $day->setTime(9, 0));
                $this->movements[] = new StockMovement("m{$n}-out", $product, $warehouse, -1.0, StockMovementType::Sale, $day->setTime(12, 0));
            }
        }
    }

    public function fetchDeals(DateRange $period): iterable
    {
        return $this->inRange($this->deals, $period);
    }

    public function fetchProducts(): iterable
    {
        return $this->products;
    }

    public function fetchWarehouses(): iterable
    {
        return $this->warehouses;
    }

    public function fetchSellers(): iterable
    {
        return $this->sellers;
    }

    public function sellerCoverage(): SellerCoverage
    {
        return $this->coverage;
    }

    public function fetchStockMovements(DateRange $period): iterable
    {
        return $this->inRange($this->movements, $period);
    }

    public function fetchStock(?DateTimeImmutable $asOf = null): iterable
    {
        $balances = $this->opening;
        foreach ($this->movements as $m) {
            if ($asOf === null || $m->date->format('Y-m-d') <= $asOf->format('Y-m-d')) {
                $key = "{$m->productId}|{$m->warehouseId}";
                $balances[$key] = ($balances[$key] ?? 0.0) + $m->quantity;
            }
        }
        foreach ($balances as $key => $quantity) {
            [$product, $warehouse] = explode('|', $key);
            yield new StockBalance($product, $warehouse, $quantity);
        }
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @template T of Deal|StockMovement
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    protected function inRange(array $items, DateRange $period): array
    {
        $from = $period->start->format('Y-m-d');
        $to = $period->end->format('Y-m-d');

        return array_values(array_filter($items, static fn ($x) => $x->date->format('Y-m-d') >= $from && $x->date->format('Y-m-d') <= $to));
    }
}
