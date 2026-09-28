<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Staging\StagingProduct;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Core\Widgets\DTO\ValueRange;
use App\Models\MetricsSnapshot;
use App\Models\User;
use App\Repositories\DbProductCategoryResolver;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/** Полный расчёт на Small-моке за июнь–август 2026. */
function runCategoryFilterPipeline(): void
{
    $adapter = new MockAdapter(MockDataProfile::Small, 42);
    app(ReferenceSyncService::class)->sync($adapter);
    (new EloquentMetricsSnapshotWriter)->write(app(MetricsCalculationService::class)->calculate(
        $adapter,
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    ));
}

/** @return array<string, string> productId => категория по справочнику в БД */
function categoryOfProducts(): array
{
    return StagingProduct::query()->pluck('category', 'external_id')->map(fn ($c) => (string) $c)->all();
}

/**
 * Товары a (Инструменты), b (без категории: null), c (пустая строка) в
 * справочнике и x — вне справочника; у каждого снэпшоты revenue и
 * days_of_stock на двух складах.
 */
function seedCategoryFilterFixture(): void
{
    StagingProduct::create(['external_id' => 'a', 'name' => 'A', 'category' => 'Инструменты', 'synced_at' => now()]);
    StagingProduct::create(['external_id' => 'b', 'name' => 'B', 'category' => null, 'synced_at' => now()]);
    StagingProduct::create(['external_id' => 'c', 'name' => 'C', 'category' => '', 'synced_at' => now()]);

    $records = [];
    foreach (['a' => 40.0, 'b' => 30.0, 'c' => 20.0, 'x' => 10.0] as $id => $value) {
        $records[] = new MetricsSnapshotRecord('product', $id, 'revenue', $value, 'month:2026-08');
        foreach (['w1', 'w2'] as $warehouse) {
            $records[] = new MetricsSnapshotRecord('product_warehouse', "{$id}:{$warehouse}", 'days_of_stock', $value / 10, 'month:2026-08');
        }
    }
    (new EloquentMetricsSnapshotWriter)->write($records);
}

it('filters product and product × warehouse rows by the product category, with NO_CATEGORY as the calculator sees it', function () {
    seedCategoryFilterFixture();
    $repo = new EloquentMetricsComparisonRepository;
    $period = Period::fromKey('month:2026-08');
    $ids = fn (string $metric, string $type, ?string $category) => array_map(
        fn ($r) => $r->entityId,
        $repo->top($metric, $type, $period, null, RankBy::Value, Direction::Desc, productCategory: $category),
    );

    expect($ids('revenue', 'product', 'Инструменты'))->toBe(['a'])
        ->and($ids('revenue', 'product', CategoryRevenueCalculator::NO_CATEGORY))->toBe(['b', 'c', 'x'])
        ->and($ids('revenue', 'product', 'Нет такой'))->toBe([])
        ->and($ids('days_of_stock', 'product_warehouse', 'Инструменты'))->toBe(['a:w1', 'a:w2'])
        ->and($repo->count('days_of_stock', 'product_warehouse', $period, productCategory: CategoryRevenueCalculator::NO_CATEGORY))->toBe(6)
        ->and($repo->bucketCounts('revenue', 'product', $period, [ValueRange::halfOpen(0.0, 25.0), ValueRange::halfOpen(25.0, null)], 'Инструменты'))->toBe([0, 1])
        ->and($repo->bucketCounts('revenue', 'product', $period, [ValueRange::halfOpen(0.0, 25.0), ValueRange::halfOpen(25.0, null)], CategoryRevenueCalculator::NO_CATEGORY))->toBe([2, 1]);
});

it('refuses the category filter for entities that are not products', function () {
    (new EloquentMetricsComparisonRepository)->top('revenue', 'category', Period::fromKey('month:2026-08'), 10, productCategory: 'Инструменты');
})->throws(InvalidArgumentException::class);

it('lists reference categories alphabetically with NO_CATEGORY last only when some product lacks one', function () {
    expect((new DbProductCategoryResolver)->categories())->toBe([]);

    StagingProduct::create(['external_id' => 'a', 'name' => 'A', 'category' => 'Одежда', 'synced_at' => now()]);
    StagingProduct::create(['external_id' => 'b', 'name' => 'B', 'category' => 'Игрушки', 'synced_at' => now()]);
    StagingProduct::create(['external_id' => 'c', 'name' => 'C', 'category' => 'Одежда', 'synced_at' => now()]);
    expect((new DbProductCategoryResolver)->categories())->toBe(['Игрушки', 'Одежда']);

    StagingProduct::create(['external_id' => 'd', 'name' => 'D', 'category' => '', 'synced_at' => now()]);
    expect((new DbProductCategoryResolver)->categories())->toBe(['Игрушки', 'Одежда', CategoryRevenueCalculator::NO_CATEGORY]);
});

it('splits the top products page by category on the mock, category by category', function () {
    runCategoryFilterPipeline();
    config(['analytics.display.table_limit' => 1000]);
    $categoryOf = categoryOfProducts();
    $all = $this->get('/dashboards/top-products')->assertOk()->viewData('top');
    $categories = array_values(array_unique($categoryOf));
    expect($categories)->toHaveCount(5);

    $sum = 0;
    foreach ($categories as $category) {
        $top = $this->get('/dashboards/top-products?'.http_build_query(['category' => $category]))->assertOk()->viewData('top');
        $expected = array_values(array_filter(array_map(fn ($r) => $r->productId, $all->rows), fn ($id) => $categoryOf[$id] === $category));

        expect(array_map(fn ($r) => $r->productId, $top->rows))->toBe($expected)
            ->and($top->total)->toBe(count($expected));
        $sum += $top->total;
    }
    expect($sum)->toBe($all->total);
});

it('splits the ABC/XYZ matrix by category, keeping the classes computed over the whole range', function () {
    runCategoryFilterPipeline();
    $cells = fn ($matrix) => collect($matrix->cells)->mapWithKeys(fn ($c) => [$c->rowKey.$c->colKey => [$c->itemsCount, round($c->value, 2)]])->all();
    $all = $this->get('/dashboards/abc-xyz')->assertOk()->viewData('matrix');
    $classOf = MetricsSnapshot::query()->where('metric_key', 'abc_xyz_classification')->get()
        ->mapWithKeys(fn ($r) => [$r->entity_id => $r->value_meta['abc_class'].$r->value_meta['xyz_class']])->all();
    $categoryOf = categoryOfProducts();

    $sum = [];
    foreach (array_unique($categoryOf) as $category) {
        $response = $this->get('/dashboards/abc-xyz?'.http_build_query(['category' => $category]))->assertOk()
            ->assertSee('посчитаны по всему ассортименту');
        $matrix = $response->viewData('matrix');

        // Число товаров в ячейке — ровно товары категории с этим (общим) классом
        // (товар без продаж за окно, например неликвид, класса не получает).
        $expected = array_count_values(array_map(fn ($id) => $classOf[$id], array_keys(array_filter($categoryOf, fn ($c, $id) => $c === $category && isset($classOf[$id]), ARRAY_FILTER_USE_BOTH))));
        expect(array_map(fn ($c) => $c[0], $cells($matrix)))->toEqual($expected);
        foreach ($cells($matrix) as $key => [$items, $value]) {
            $sum[$key] = [($sum[$key][0] ?? 0) + $items, round(($sum[$key][1] ?? 0) + $value, 2)];
        }
    }
    expect($sum)->toEqual($cells($all));

    $this->get('/dashboards/abc-xyz?category=__none__')->assertOk()->assertSee('В этой категории нет товаров');
    $this->get('/dashboards/abc-xyz?category=Нет%20такой')->assertNotFound();
    $this->get('/dashboards/abc-xyz')->assertOk()->assertSee('Все категории')->assertDontSee('посчитаны по всему ассортименту');

    config(['analytics.features.categories' => false]);
    $this->get('/dashboards/abc-xyz')->assertOk()->assertDontSee('Все категории');
    $this->get('/dashboards/abc-xyz?category=Одежда')->assertNotFound();
});

it('filters the stock and turnover pages, tables and charts alike', function () {
    runCategoryFilterPipeline();
    config(['analytics.display.table_limit' => 1000]);
    $categoryOf = categoryOfProducts();
    // Категория, у которой точно есть риск дефицита в августе.
    $allRisk = $this->get('/dashboards/stock')->viewData('stockoutRisk');
    $category = $categoryOf[$allRisk->rows[0]->productId];
    $query = '?'.http_build_query(['category' => $category]);
    $chartTotal = fn ($chart) => (int) array_sum(array_map(fn ($p) => $p->value, $chart->series[0]->points));

    $stock = $this->get('/dashboards/stock'.$query)->assertOk();
    $risk = $stock->viewData('stockoutRisk');
    expect($risk->rows)->not->toBeEmpty()
        ->and(array_unique(array_map(fn ($r) => $categoryOf[$r->productId], $risk->rows)))->toBe([$category])
        ->and(array_unique(array_map(fn ($r) => $categoryOf[$r->productId], $stock->viewData('deadStock')->rows)))->toBeIn([[], [$category]]);

    expect($risk->total)->toBe(count(array_filter($allRisk->rows, fn ($r) => $categoryOf[$r->productId] === $category)));

    $daysChart = $stock->viewData('daysOfStockChart');
    $allDaysChart = $this->get('/dashboards/stock')->viewData('daysOfStockChart');
    expect($chartTotal($daysChart))->toBeLessThan($chartTotal($allDaysChart))->toBeGreaterThan(0);

    $turnover = $this->get('/dashboards/turnover'.$query)->assertOk();
    $lowest = $turnover->viewData('lowest');
    expect(array_unique(array_map(fn ($r) => $categoryOf[$r->productId], $lowest->rows)))->toBe([$category])
        ->and($lowest->total)->toBe(count(array_filter($categoryOf, fn ($c) => $c === $category)))
        ->and($chartTotal($turnover->viewData('distribution')))->toBe($lowest->total);
});

it('renders the filter form and keeps the category in switcher and export links', function () {
    runCategoryFilterPipeline();

    $this->get('/dashboards/top-products?period=month:2026-08&category=Одежда')->assertOk()
        ->assertSee('<option value="Одежда" selected>Одежда</option>', false)
        ->assertSee('Все категории')
        ->assertSee('<input type="hidden" name="period" value="month:2026-08">', false)
        ->assertSee(e(route('dashboards.top-products.export', ['period' => 'month:2026-08', 'category' => 'Одежда'])), false);

    $this->get('/dashboards/stock?category=Одежда')->assertOk()
        ->assertSee(e(route('dashboards.stock.export.dead-stock', ['period' => 'month:2026-08', 'category' => 'Одежда'])), false)
        ->assertSee(e(route('dashboards.stock.export.stockout-risk', ['period' => 'month:2026-08', 'category' => 'Одежда'])), false);
    $this->get('/dashboards/turnover?category=Одежда')->assertOk()
        ->assertSee(e(route('dashboards.turnover.export', ['period' => 'month:2026-08', 'category' => 'Одежда'])), false);
});

it('treats the empty "all categories" choice as no filter and rejects unknown values', function () {
    runCategoryFilterPipeline();

    $all = $this->get('/dashboards/top-products')->viewData('top');
    expect($this->get('/dashboards/top-products?category=')->assertOk()->viewData('top')->total)->toBe($all->total);

    foreach (['/dashboards/top-products', '/dashboards/stock', '/dashboards/turnover', '/dashboards/turnover/export'] as $url) {
        $this->get($url.'?category=Нет%20такой')->assertNotFound();
        $this->get($url.'?category[]=Одежда')->assertNotFound();
    }
    // «Без категории» допустима, даже если в справочнике таких товаров нет (сделки вне справочника).
    $this->get('/dashboards/top-products?category=__none__')->assertOk();
});

it('has no filter when the categories flag is off', function () {
    runCategoryFilterPipeline();
    config(['analytics.features.categories' => false]);

    $this->get('/dashboards/top-products')->assertOk()->assertDontSee('Все категории');
    $this->get('/dashboards/top-products?category=Одежда')->assertNotFound();
    $this->get('/dashboards/top-products/export?category=Одежда')->assertNotFound();
});

it('exports only the category rows under a transliterated file name', function () {
    runCategoryFilterPipeline();
    $categoryOf = categoryOfProducts();

    $response = $this->get('/dashboards/top-products/export?category=Электроника')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="top-products-2026-08-elektronika.csv"');
    $rows = array_map(fn ($line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($response->getContent(), 3)))));
    expect(count($rows))->toBeGreaterThan(1)
        ->and(array_unique(array_map(fn ($r) => $categoryOf[$r[2]], array_slice($rows, 1))))->toBe(['Электроника']);

    $this->get('/dashboards/turnover/export?category=__none__')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="turnover-2026-08-bez-kategorii.csv"');
    $this->get('/dashboards/stock/export/stockout-risk?category=Электроника')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="stockout-risk-2026-08-elektronika.csv"');
});

it('links each category on the categories page to its top products', function () {
    runCategoryFilterPipeline();

    $this->get('/dashboards/categories')->assertOk()
        ->assertSee(e(route('dashboards.top-products', ['period' => 'month:2026-08', 'category' => 'Электроника'])), false);

    config(['analytics.features.top_products' => false]);
    $this->get('/dashboards/categories')->assertOk()->assertDontSee(e(route('dashboards.top-products', ['period' => 'month:2026-08', 'category' => 'Электроника'])), false);
});

it('filters from the database without touching the data source', function () {
    runCategoryFilterPipeline();
    app()->instance(DataSourceAdapter::class, throwingAdapter());

    $this->get('/dashboards/top-products?category=Одежда')->assertOk()->assertSee('Одежда');
});
