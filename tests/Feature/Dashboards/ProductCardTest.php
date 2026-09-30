<?php

use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\Staging\StagingProduct;
use App\Models\Staging\StagingWarehouse;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $meta */
function cardSnap(string $entityType, string $entityId, string $metric, float $value, string $period, array $meta = []): void
{
    (new EloquentMetricsSnapshotWriter)->write([new MetricsSnapshotRecord($entityType, $entityId, $metric, $value, $period, $meta)]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    StagingProduct::create(['external_id' => 'p1', 'name' => 'Дрель', 'category' => 'Инструменты', 'synced_at' => now()]);
    StagingProduct::create(['external_id' => 'p10', 'name' => 'Другой товар', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w1', 'name' => 'Центральный', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w2', 'name' => 'Северный', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w3', 'name' => 'Южный', 'synced_at' => now()]);
});

/** Июнь и август рассчитаны, июль — нет; товар p1 продавался в июне и августе. */
function seedCard(): void
{
    cardSnap('product', 'p1', 'revenue', 100, 'month:2026-06');
    cardSnap('product', 'p10', 'revenue', 7, 'month:2026-06');
    cardSnap('product', 'p1', 'revenue', 150, 'month:2026-08');
    cardSnap('product', 'p10', 'revenue', 5, 'month:2026-05');
    cardSnap('product', 'p1', 'turnover', 1.5, 'month:2026-08', ['units_sold' => 15, 'closing_stock' => 12, 'avg_stock' => 10]);
    cardSnap('product', 'p1', 'days_since_last_sale', 3, 'month:2026-08', ['stock_qty' => 12]);
    cardSnap('product', 'p1', 'abc_xyz_classification', 0.2, 'month:2026-08', ['abc_class' => 'A', 'xyz_class' => 'Y']);
    cardSnap('product_warehouse', 'p1:w1', 'days_of_stock', 40, 'month:2026-08', ['stock_qty' => 8, 'daily_rate' => 0.2]);
    cardSnap('product_warehouse', 'p1:w2', 'days_of_stock', 5, 'month:2026-08', ['stock_qty' => 1, 'daily_rate' => 0.2]);
    cardSnap('product_warehouse', 'p1:w3', 'stock_no_demand', 3, 'month:2026-08', ['stock_qty' => 3]);
    cardSnap('product_warehouse', 'p10:w1', 'days_of_stock', 1, 'month:2026-08', ['stock_qty' => 1, 'daily_rate' => 1]);
}

it('shows the product with the metrics of the latest month', function () {
    seedCard();

    $response = $this->get('/dashboards/products/p1')->assertOk()
        ->assertSee('Дрель')->assertSee('Инструменты')->assertSee('AY')
        ->assertSee('Показатели за 2026-08')->assertSee('риск дефицита')->assertSee('нет продаж');

    $card = $response->viewData('card');
    expect($card->period)->toBe('month:2026-08')
        ->and($card->month->revenue)->toBe(150.0)
        ->and($card->month->unitsSold)->toBe(15.0)
        ->and($card->month->closingStock)->toBe(12.0)
        ->and($card->month->turnover)->toBe(1.5)
        ->and($card->daysSinceLastSale)->toBe(3.0)
        ->and($card->abcClass)->toBe('A')->and($card->xyzClass)->toBe('Y')
        // Июль не рассчитан: базы изменения нет, в динамике его нет.
        ->and($card->previousRevenue)->toBeNull()
        ->and(array_map(fn ($r) => [$r->period, $r->revenue], $card->history))
        ->toBe([['month:2026-05', 0.0], ['month:2026-06', 100.0], ['month:2026-08', 150.0]])
        // Склады: сначала по дням до обнуления, затем без продаж; чужой товар p10 не попал.
        ->and(array_map(fn ($r) => [$r->warehouseName, $r->daysOfStock], $card->warehouses))
        ->toBe([['Северный', 5.0], ['Центральный', 40.0], ['Южный', null]]);

    expect($response->viewData('revenueChart')->series[0]->points)->toHaveCount(3);
});

it('compares revenue with the previous month, zero when it was calculated without sales of the product', function () {
    seedCard();

    $card = $this->get('/dashboards/products/p1?period=month:2026-06')->assertOk()->viewData('card');
    // Май рассчитан (есть выручка p10), p1 в мае не продавался — база 0, процента нет.
    expect($card->previousRevenue)->toBe(0.0)->and($card->revenueDeltaPercent())->toBeNull()
        ->and($card->month->turnover)->toBeNull()
        ->and($card->warehouses)->toBe([]);

    cardSnap('product', 'p1', 'revenue', 120, 'month:2026-07');
    $card = $this->get('/dashboards/products/p1')->viewData('card');
    expect($card->previousRevenue)->toBe(120.0)->and($card->revenueDeltaPercent())->toBe(25.0);
});

it('marks dead stock and lost sales', function () {
    seedCard();
    cardSnap('product', 'p10', 'days_since_last_sale', 200, 'month:2026-08', ['stock_qty' => 4, 'no_sales_in_lookback' => true]);
    cardSnap('product', 'p10', 'lost_sales', 7, 'month:2026-08', ['last_sale_period' => 'month:2026-06']);

    $response = $this->get('/dashboards/products/p10')->assertOk()
        ->assertSee('не меньше 200')->assertSee('неликвид')->assertSee('без категории')
        ->assertSee('выручка за 2026-06 была 7');

    expect($response->viewData('card')->lostSales)->toBe(7.0);
});

it('returns 404 for an unknown product and an invalid period', function () {
    seedCard();

    $this->get('/dashboards/products/nope')->assertNotFound();
    $this->get('/dashboards/products/p1?period=garbage')->assertNotFound();
    $this->get('/dashboards/products/p1?period=quarter:2026-Q3')->assertNotFound();
});

it('shows an empty state before the first calculation', function () {
    $this->get('/dashboards/products/p1')->assertOk()->assertSee('Дрель')
        ->assertSee('расчёт метрик ещё не выполнялся')->assertDontSee('<canvas', false);
});

it('switches months over the months with data', function () {
    seedCard();

    $this->get('/dashboards/products/p1')->assertOk()
        ->assertSee('period=month%3A2026-06', false)->assertDontSee('rel="next"', false);
});

it('hides blocks of disabled features', function () {
    seedCard();
    config(['analytics.features.turnover' => false, 'analytics.features.dead_stock' => false, 'analytics.features.stockout_risk' => false]);

    $this->get('/dashboards/products/p1')->assertOk()
        ->assertDontSee('Оборачиваемость</th>', false)->assertDontSee('Дней без продаж')->assertDontSee('Склады');
});

it('links product names in tables to the card of the same month, unless the card is disabled', function () {
    seedCard();
    $card = route('dashboards.product', ['product' => 'p1', 'period' => 'month:2026-08']);

    $this->get('/dashboards/top-products')->assertOk()->assertSee($card, false);
    $this->get('/dashboards/turnover')->assertOk()->assertSee($card, false);
    $this->get('/dashboards/stock')->assertOk()->assertSee($card, false);

    // Товар вне справочника (название = id) — без ссылки: его карточка — 404.
    cardSnap('product', 'ghost', 'revenue', 1, 'month:2026-08');
    $this->get('/dashboards/top-products')->assertOk()->assertSee('<td>ghost</td>', false);

    config(['analytics.features.product_card' => false]);
    $this->get('/dashboards/products/p1')->assertNotFound();
    $this->get('/dashboards/top-products')->assertOk()->assertSee('Дрель')->assertDontSee('/dashboards/products/', false);
});
