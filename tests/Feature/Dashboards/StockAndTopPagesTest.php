<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\RevenueByPeriodCalculator;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingWarehouse;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Снэпшоты через реальные калькуляторы на Small-моке. */
function seedFromMock(int $seed): MockAdapter
{
    $adapter = new MockAdapter(MockDataProfile::Small, $seed);
    $writer = new EloquentMetricsSnapshotWriter;
    $range = new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'));

    $deals = iterator_to_array($adapter->fetchDeals($range), false);
    $writer->write((new RevenueByPeriodCalculator)->calculate($deals, $range));
    $writer->write((new DeadStockCalculator(90))->calculate($adapter, $range));
    $writer->write((new DaysOfStockCalculator(28, 7))->calculate($adapter, $range));

    // Названия страницы берут из справочника в БД — наполняем его тем же адаптером.
    app(ReferenceSyncService::class)->sync($adapter);

    return $adapter;
}

/** @return array<string, array<string, float>> 'Y-m' => [productId => sum] */
function pagesRevenueOracle(MockAdapter $adapter): array
{
    $sums = [];
    foreach ($adapter->fetchDeals(new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31'))) as $deal) {
        $sums[$deal->date->format('Y-m')][$deal->productId] = ($sums[$deal->date->format('Y-m')][$deal->productId] ?? 0.0) + $deal->amount;
    }

    return array_map(fn ($byProduct) => array_map(fn ($v) => round($v, 4), $byProduct), $sums);
}

function fakeNames(array $names = []): object
{
    $resolver = new class($names) implements ProductNameResolver
    {
        public int $calls = 0;

        public function __construct(public array $names) {}

        public function names(array $productIds): array
        {
            $this->calls++;

            return array_intersect_key($this->names, array_flip($productIds));
        }
    };
    app()->instance(ProductNameResolver::class, $resolver);

    return $resolver;
}

/** @param array<string, array<string, float>> $data metric => ... см. вызовы */
function seedRaw(string $metric, string $entityType, string $period, array $values, array $meta = []): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($id, $value) => new MetricsSnapshotRecord($entityType, (string) $id, $metric, (float) $value, $period, $meta[$id] ?? []),
        array_keys($values),
        array_values($values),
    ));
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['analytics.display.table_limit' => 1000]);
});

it('redirects guests to the login page on both pages', function (string $path) {
    auth()->logout();

    $this->get($path)->assertRedirect('/login');
})->with(['/dashboards/stock', '/dashboards/top-products']);

it('shows exactly the manifest dead products in the dead-stock table', function (int $seed) {
    $adapter = seedFromMock($seed);
    $dead = array_keys($adapter->manifest()->deadProducts);

    $rows = $this->get('/dashboards/stock')->assertOk()->viewData('deadStock');

    $shown = array_map(fn ($r) => $r->productId, $rows->rows);
    sort($dead);
    sort($shown);
    expect($dead)->not->toBeEmpty()
        ->and($shown)->toBe($dead)
        ->and($rows->total)->toBe(count($dead))
        ->and($rows->period)->toBe('month:2026-08')
        ->and(array_map(fn ($r) => $r->daysSinceLastSale, $rows->rows))->toBe(collect($rows->rows)->pluck('daysSinceLastSale')->sortDesc()->values()->all());
})->with([1, 2, 3]);

it('renders names, stock and the "shown X of Y" caption for dead stock', function () {
    seedFromMock(1);
    config(['analytics.display.table_limit' => 1]);

    $this->get('/dashboards/stock')->assertOk()
        ->assertSee('Неликвиды')
        ->assertSee('Показано 1 из')
        ->assertSee('Product ');
});

it('takes the dead-stock threshold from the config', function () {
    $adapter = seedFromMock(1);
    $atDefault = count($this->get('/dashboards/stock')->viewData('deadStock')->rows);

    config(['analytics.display.dead_stock_display_days' => 10_000]);
    expect($this->get('/dashboards/stock')->viewData('deadStock')->rows)->toBeEmpty()
        ->and($atDefault)->toBeGreaterThan(0);

    config(['analytics.display.dead_stock_display_days' => 0]);
    expect(count($this->get('/dashboards/stock')->viewData('deadStock')->rows))->toBeGreaterThanOrEqual($atDefault);
});

it('shows a lower bound "≥ N" for products with no sales in the whole lookback', function () {
    fakeNames(['p1' => 'Товар 1', 'p2' => 'Товар 2']);
    seedRaw('days_since_last_sale', 'product', 'month:2026-08', ['p1' => 120, 'p2' => 100], [
        'p1' => ['stock_qty' => 5, 'no_sales_in_lookback' => true],
        'p2' => ['stock_qty' => 3.5],
    ]);

    $html = $this->get('/dashboards/stock')->assertOk()->getContent();

    expect($html)->toContain('≥ 120')->and($html)->not->toContain('≥ 100')
        ->and($html)->toContain('Показано 2 из 2');
});

it('puts the manifest near-zero pairs into the stockout-risk table, ascending', function (int $seed) {
    $adapter = seedFromMock($seed);
    $near = $adapter->manifest()->nearZeroProducts;
    config(['analytics.display.stockout_risk_days' => max(array_column($near, 'days_to_zero'))]);

    $rows = $this->get('/dashboards/stock')->assertOk()->viewData('stockoutRisk');

    $shown = array_map(fn ($r) => $r->productId.':'.$r->warehouseId, $rows->rows);
    foreach ($near as $productId => $fact) {
        expect($shown)->toContain($productId.':'.$fact['warehouse_id']);
    }
    $days = array_map(fn ($r) => $r->daysOfStock, $rows->rows);
    $sorted = $days;
    sort($sorted);
    expect($days)->toBe($sorted)->and($rows->total)->toBe(count($rows->rows));
    foreach ($rows->rows as $r) {
        expect($r->daysOfStock)->toBeLessThanOrEqual(max(array_column($near, 'days_to_zero')));
    }
})->with([1, 2, 3]);

it('takes the stockout-risk threshold from the config', function () {
    seedFromMock(1);
    config(['analytics.display.stockout_risk_days' => 0]);
    $atZero = count($this->get('/dashboards/stock')->viewData('stockoutRisk')->rows);

    config(['analytics.display.stockout_risk_days' => 10_000]);
    $atHuge = count($this->get('/dashboards/stock')->viewData('stockoutRisk')->rows);

    expect($atHuge)->toBeGreaterThan($atZero);
});

it('shows the same top-5 and anti-top-5 as an independent oracle, with MoM columns', function () {
    $adapter = seedFromMock(1);
    $oracle = pagesRevenueOracle($adapter);
    config(['analytics.display.table_limit' => 5]);

    $response = $this->get('/dashboards/top-products')->assertOk();

    $order = function (int $dir) use ($oracle) {
        $ids = array_keys($oracle['2026-08']);
        usort($ids, fn ($a, $b) => ($dir * ($oracle['2026-08'][$a] <=> $oracle['2026-08'][$b])) ?: strcmp($a, $b));

        return array_slice($ids, 0, 5);
    };

    foreach (['top' => -1, 'antiTop' => 1] as $var => $dir) {
        $rows = $response->viewData($var)->rows;
        expect(array_map(fn ($r) => $r->productId, $rows))->toBe($order($dir));
        foreach ($rows as $r) {
            $july = $oracle['2026-07'][$r->productId] ?? null;
            expect($r->value)->toEqualWithDelta($oracle['2026-08'][$r->productId], 1e-6)
                ->and($r->baseValue)->toEqualWithDelta($july, 1e-6);
            if ($july !== null) {
                expect($r->deltaAbs)->toEqualWithDelta($oracle['2026-08'][$r->productId] - $july, 1e-6);
            }
        }
    }
    $response->assertSee('Топ')->assertSee('Наименьшая выручка среди проданных за месяц')->assertSee('Показано 5 из');
});

it('explains the anti-top block and points to dead stock only when the dead_stock flag is on', function () {
    seedFromMock(1);
    $note = 'Товары без продаж за месяц смотрите в разделе «Неликвиды».';

    config(['analytics.features.dead_stock' => true]);
    $this->get('/dashboards/top-products')->assertOk()
        ->assertSee('Наименьшая выручка среди проданных за месяц')
        ->assertSee($note);

    config(['analytics.features.dead_stock' => false]);
    $this->get('/dashboards/top-products')->assertOk()
        ->assertSee('Наименьшая выручка среди проданных за месяц')
        ->assertDontSee($note)
        ->assertDontSee('Неликвиды');
});

it('renders MoM arrows, colors and a dash for a missing percent', function () {
    fakeNames();
    seedRaw('revenue', 'product', 'month:2026-07', ['up' => 100, 'down' => 100, 'zero' => 0]);
    seedRaw('revenue', 'product', 'month:2026-08', ['up' => 150, 'down' => 50, 'zero' => 10, 'fresh' => 5]);

    $html = $this->get('/dashboards/top-products')->assertOk()->getContent();

    expect($html)->toContain('▲')->toContain('▼')->toContain('kpi-delta-up')->toContain('kpi-delta-down')
        ->and($html)->toContain('—');
});

it('hides a page and its nav item when its flag is off, and shows them when on', function () {
    $this->get('/dashboards/overview')->assertOk()->assertSee('Остатки')->assertSee('Топ товаров');

    config(['analytics.features.top_products' => false]);
    $this->get('/dashboards/top-products')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('Топ товаров')->assertSee('Остатки');
    $this->get('/dashboards/stock')->assertOk();
});

it('hides only the widget of a disabled stock flag', function () {
    seedFromMock(1);

    config(['analytics.features.dead_stock' => false]);
    $this->get('/dashboards/stock')->assertOk()->assertDontSee('Неликвиды')->assertSee('Риск дефицита');

    config(['analytics.features.dead_stock' => true, 'analytics.features.stockout_risk' => false]);
    $this->get('/dashboards/stock')->assertOk()->assertSee('Неликвиды')->assertDontSee('Риск дефицита');
});

it('returns 404 and hides the nav item when both stock flags are off', function () {
    config(['analytics.features.dead_stock' => false, 'analytics.features.stockout_risk' => false]);

    $this->get('/dashboards/stock')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('Остатки');
});

it('still calculates metrics regardless of flags', function () {
    config(['analytics.features.dead_stock' => false, 'analytics.features.stockout_risk' => false, 'analytics.features.top_products' => false]);

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->assertExitCode(0);

    expect(DB::table('metrics_snapshots')->whereIn('metric_key', ['days_since_last_sale', 'days_of_stock', 'revenue'])->distinct()->count('metric_key'))->toBe(3);
});

it('accepts a valid month period and rejects invalid ones with 404', function () {
    seedFromMock(1);

    $this->get('/dashboards/top-products?period=month:2026-07')->assertOk()->assertSee('2026-07');
    $this->get('/dashboards/stock?period=month:2026-07')->assertOk();

    foreach (['garbage', 'month:2026-13', 'week:2026-W10', 'day:2026-07-01', 'year:2026', 'month:', ''] as $bad) {
        $this->get('/dashboards/top-products?period='.$bad)->assertNotFound();
        $this->get('/dashboards/stock?period='.$bad)->assertNotFound();
    }
    $this->get('/dashboards/stock?period[]=month:2026-07')->assertNotFound();
});

it('shows an empty state, not an error, for a valid period without data', function () {
    seedFromMock(1);

    $this->get('/dashboards/top-products?period=month:2020-01')->assertOk()->assertSee('Нет данных за период');
    $this->get('/dashboards/stock?period=month:2020-01')->assertOk()->assertSee('Нет данных за период');
});

it('shows an empty state on both pages when there are no snapshots at all', function (string $path) {
    $this->get($path)->assertOk()->assertSee('Нет данных: расчёт метрики ещё не выполнялся');
})->with(['/dashboards/stock', '/dashboards/top-products']);

it('escapes product names instead of rendering them as HTML', function () {
    fakeNames(['p1' => '<script>alert(1)</script>', 'p2' => '<b>bold</b>']);
    seedRaw('revenue', 'product', 'month:2026-08', ['p1' => 10, 'p2' => 5]);
    seedRaw('days_since_last_sale', 'product', 'month:2026-08', ['p1' => 200], ['p1' => ['stock_qty' => 1]]);
    seedRaw('days_of_stock', 'product_warehouse', 'month:2026-08', ['p2:w1' => 2], ['p2:w1' => ['stock_qty' => 1, 'daily_rate' => 1]]);

    foreach (['/dashboards/top-products', '/dashboards/stock'] as $path) {
        $this->get($path)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>bold</b>', false);
    }
    $this->get('/dashboards/stock')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('falls back to the id when a product has no name', function () {
    fakeNames();
    seedRaw('revenue', 'product', 'month:2026-08', ['prod-x' => 10]);

    $this->get('/dashboards/top-products')->assertOk()->assertSee('prod-x');
});

it('does not grow the number of SQL queries with the number of rows', function () {
    $count = function (int $rows) {
        DB::table('metrics_snapshots')->delete();
        $resolver = fakeNames(collect(range(1, $rows))->mapWithKeys(fn ($i) => ["p{$i}" => "Товар {$i}"])->all());
        $ids = collect(range(1, $rows))->mapWithKeys(fn ($i) => ["p{$i}" => 100 + $i])->all();
        $pairs = collect(range(1, $rows))->mapWithKeys(fn ($i) => ["p{$i}:w1" => $i])->all();
        seedRaw('revenue', 'product', 'month:2026-07', $ids);
        seedRaw('revenue', 'product', 'month:2026-08', $ids);
        seedRaw('days_since_last_sale', 'product', 'month:2026-08', $ids, array_map(fn () => ['stock_qty' => 1], $ids));
        seedRaw('days_of_stock', 'product_warehouse', 'month:2026-08', $pairs, array_map(fn () => ['stock_qty' => 1, 'daily_rate' => 1], $pairs));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        foreach (['/dashboards/stock', '/dashboards/top-products', '/dashboards/overview'] as $path) {
            $this->get($path)->assertOk();
        }

        return [$queries, $resolver->calls];
    };

    [$few] = $count(3);
    [$many, $calls] = $count(40);

    expect($many)->toBe($few)->and($calls)->toBeGreaterThan(0);
});

it('renders the pages with an adapter that throws on every fetch', function () {
    seedFromMock(1);
    app()->instance(DataSourceAdapter::class, throwingAdapter());

    foreach (['/dashboards/stock', '/dashboards/top-products'] as $path) {
        $this->get($path)->assertOk()->assertSee('Product ');
    }
});

it('shows warehouse names from the reference with an id fallback, and escapes them', function () {
    fakeNames(['p1' => 'Товар']);
    StagingWarehouse::create(['external_id' => 'w1', 'name' => '<i>Главный</i> склад', 'synced_at' => now()]);
    seedRaw('days_of_stock', 'product_warehouse', 'month:2026-08', ['p1:w1' => 2, 'p1:w2' => 3], [
        'p1:w1' => ['stock_qty' => 1, 'daily_rate' => 1], 'p1:w2' => ['stock_qty' => 1, 'daily_rate' => 1],
    ]);

    $response = $this->get('/dashboards/stock')->assertOk();

    $response->assertSee('&lt;i&gt;Главный&lt;/i&gt; склад', false)->assertDontSee('<i>Главный</i>', false)
        ->assertSee('<td>w2</td>', false);
});

it('shows a product name from the reference, falls back to the id, and escapes markup', function () {
    StagingProduct::create(['external_id' => 'p1', 'name' => '<b>Жирный</b> товар', 'synced_at' => now()]);
    seedRaw('revenue', 'product', 'month:2026-08', ['p1' => 10, 'p-unknown' => 5]);

    $this->get('/dashboards/top-products')->assertOk()
        ->assertSee('&lt;b&gt;Жирный&lt;/b&gt; товар', false)->assertDontSee('<b>Жирный</b>', false)
        ->assertSee('p-unknown');
});
