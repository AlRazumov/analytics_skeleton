<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Product;
use App\Core\Domain\Warehouse;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingWarehouse;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\Contracts\WarehouseNameResolver;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Адаптер со справочниками из массивов; остальное не нужно. */
function referenceAdapter(array $products, array $warehouses = []): DataSourceAdapter
{
    return new class($products, $warehouses) implements DataSourceAdapter
    {
        public function __construct(public array $products, public array $warehouses) {}

        public function fetchProducts(): iterable
        {
            yield from $this->products;
        }

        public function fetchWarehouses(): iterable
        {
            yield from $this->warehouses;
        }

        public function fetchDeals(DateRange $period): iterable
        {
            return [];
        }

        public function fetchStockMovements(DateRange $period): iterable
        {
            return [];
        }

        public function fetchStock(?DateTimeImmutable $asOf = null): iterable
        {
            return [];
        }

        public function fetchSellers(): iterable
        {
            return [];
        }

        public function sellerCoverage(): SellerCoverage
        {
            return SellerCoverage::None;
        }

        public function capabilities(): array
        {
            return [];
        }
    };
}

function snapshotRows(string $model): array
{
    return $model::query()->orderBy('external_id')->get(['id', 'external_id', 'name', 'meta'])->map->toArray()->all();
}

it('is idempotent: a second run leaves the same rows', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $sync = app(ReferenceSyncService::class);

    $first = $sync->sync($adapter);
    $rows = [snapshotRows(StagingProduct::class), snapshotRows(StagingWarehouse::class)];
    $second = $sync->sync($adapter);

    expect($first)->toBe(['products' => 50, 'warehouses' => MockDataProfile::Small->warehouseCount(), 'sellers' => MockDataProfile::Small->sellerCount()])
        ->and($second)->toBe($first)
        ->and([snapshotRows(StagingProduct::class), snapshotRows(StagingWarehouse::class)])->toEqual($rows)
        ->and(StagingProduct::count())->toBe(50);
});

it('updates name, category and meta when the source changes them', function () {
    $sync = app(ReferenceSyncService::class);
    $sync->sync(referenceAdapter([new Product('p1', 'Старое', 'a', ['x' => 1])], [new Warehouse('w1', 'Старый склад')]));
    $id = StagingProduct::where('external_id', 'p1')->value('id');

    $sync->sync(referenceAdapter([new Product('p1', 'Новое', 'b', ['x' => 2])], [new Warehouse('w1', 'Новый склад', ['k' => 'v'])]));

    $product = StagingProduct::where('external_id', 'p1')->sole();
    expect($product->id)->toBe($id)
        ->and($product->name)->toBe('Новое')->and($product->category)->toBe('b')->and($product->meta)->toBe(['x' => 2])
        ->and(StagingWarehouse::where('external_id', 'w1')->sole())->name->toBe('Новый склад');
});

it('does not delete records that disappeared from the source', function () {
    $sync = app(ReferenceSyncService::class);
    $sync->sync(referenceAdapter([new Product('p1', 'A'), new Product('p2', 'B')], [new Warehouse('w1', 'W1'), new Warehouse('w2', 'W2')]));

    $sync->sync(referenceAdapter([new Product('p1', 'A2')], [new Warehouse('w1', 'W1')]));

    expect(StagingProduct::pluck('name', 'external_id')->all())->toEqual(['p1' => 'A2', 'p2' => 'B'])
        ->and(StagingWarehouse::count())->toBe(2);
});

it('syncs the Medium profile catalog', function () {
    $counts = app(ReferenceSyncService::class)->sync(new MockAdapter(MockDataProfile::Medium, 1));

    expect($counts['products'])->toBe(500)
        ->and(StagingProduct::count())->toBe(500)
        ->and(StagingProduct::where('external_id', 'prod-500')->value('name'))->toBe('Product 500');
});

it('upserts in chunks of 1000', function () {
    $products = array_map(fn ($i) => new Product("p{$i}", "Товар {$i}"), range(1, 2500));

    $upserts = 0;
    DB::listen(function ($q) use (&$upserts) {
        if (str_starts_with($q->sql, 'insert into "staging_products"')) {
            $upserts++;
        }
    });
    app(ReferenceSyncService::class)->sync(referenceAdapter($products));

    expect(StagingProduct::count())->toBe(2500)->and($upserts)->toBe(3);
});

it('survives a duplicate id inside one chunk (the last one wins)', function () {
    app(ReferenceSyncService::class)->sync(referenceAdapter([new Product('p1', 'первый'), new Product('p1', 'последний')]));

    expect(StagingProduct::where('external_id', 'p1')->sole()->name)->toBe('последний');
});

it('runs reference:sync from the configured source and syncs before metrics:calculate', function () {
    config(['analytics.mock.profile' => 'small']);

    $this->artisan('reference:sync')
        ->expectsOutputToContain('продавцов — ')
        ->assertExitCode(0);
    expect(StagingProduct::count())->toBe(50);

    StagingProduct::query()->delete();
    StagingWarehouse::query()->delete();
    $this->artisan('metrics:calculate', ['--period' => '2026-08:2026-08'])->assertExitCode(0);
    expect(StagingProduct::count())->toBe(50)->and(StagingWarehouse::count())->toBeGreaterThan(0);
});

it('resolves names from the database with one query, without the adapter', function () {
    app(ReferenceSyncService::class)->sync(referenceAdapter([new Product('p1', 'Один'), new Product('p2', 'Два')], [new Warehouse('w1', 'Склад А')]));
    app()->bind(DataSourceAdapter::class, fn () => throw new RuntimeException('adapter must not be used'));

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect(app(ProductNameResolver::class)->names(['p1', 'p2', 'p3', 'p1']))->toBe(['p1' => 'Один', 'p2' => 'Два'])
        ->and($queries)->toBe(1)
        ->and(app(WarehouseNameResolver::class)->names(['w1', 'w9']))->toBe(['w1' => 'Склад А'])
        ->and($queries)->toBe(2)
        ->and(app(ProductNameResolver::class)->names([]))->toBe([])
        ->and($queries)->toBe(2);
});
