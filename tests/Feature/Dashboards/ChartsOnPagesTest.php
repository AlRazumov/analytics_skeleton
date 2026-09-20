<?php

use App\Core\Staging\StagingProduct;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, float> $values entityId => value */
function chartSeed(string $metric, string $entityType, string $period, array $values, array $meta = ['stock_qty' => 1, 'daily_rate' => 1]): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($id, $value) => new MetricsSnapshotRecord($entityType, (string) $id, $metric, (float) $value, $period, $meta),
        array_keys($values),
        array_values($values),
    ));
}

function chartValues($chart): array
{
    return array_map(fn ($p) => $p->value, $chart->series[0]->points);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('buckets dead stock by age from the display threshold and ignores younger products', function () {
    chartSeed('days_since_last_sale', 'product', 'month:2026-08', [
        'young' => 10, 'a' => 90, 'b' => 179, 'c' => 180, 'd' => 364, 'e' => 365, 'f' => 500,
    ]);

    $chart = $this->get('/dashboards/stock')->assertOk()->viewData('deadStockChart');

    expect(array_map(fn ($p) => $p->label, $chart->series[0]->points))->toBe(['90–179', '180–364', '365+'])
        ->and(chartValues($chart))->toBe([2.0, 2.0, 2.0]);

    config(['analytics.display.dead_stock_age_bounds' => [100], 'analytics.display.dead_stock_display_days' => 0]);
    expect(chartValues($this->get('/dashboards/stock')->viewData('deadStockChart')))->toBe([2.0, 5.0]);
});

it('explains that no-sales products are bucketed by their lower bound', function () {
    chartSeed('days_since_last_sale', 'product', 'month:2026-08', ['a' => 120], ['stock_qty' => 1, 'no_sales_in_lookback' => true]);

    $this->get('/dashboards/stock')->assertOk()->assertSee('отнесены к корзине по нижней границе возраста');
});

it('buckets days of stock into 0-7, 8-14, 15-30, 31-60, 61+', function () {
    chartSeed('days_of_stock', 'product_warehouse', 'month:2026-08', [
        'p:w1' => 0, 'p:w2' => 7.9, 'q:w1' => 8, 'q:w2' => 14, 'r:w1' => 15, 'r:w2' => 30, 's:w1' => 31, 's:w2' => 60, 't:w1' => 61, 't:w2' => 900,
    ]);

    $chart = $this->get('/dashboards/stock')->assertOk()->viewData('daysOfStockChart');

    expect(array_map(fn ($p) => $p->label, $chart->series[0]->points))->toBe(['0–7', '8–14', '15–30', '31–60', '61+'])
        ->and(chartValues($chart))->toBe([2.0, 2.0, 2.0, 2.0, 2.0]);
});

it('draws no chart and shows the empty state when there is no data', function () {
    $this->get('/dashboards/stock')->assertOk()->assertSee('Нет данных')->assertDontSee('<canvas', false);
    $this->get('/dashboards/top-products')->assertOk()->assertSee('Нет данных')->assertDontSee('<canvas', false);
});

it('hides a chart together with its widget flag', function () {
    chartSeed('days_since_last_sale', 'product', 'month:2026-08', ['a' => 120]);
    chartSeed('days_of_stock', 'product_warehouse', 'month:2026-08', ['a:w1' => 3]);

    $this->get('/dashboards/stock')->assertOk()
        ->assertSee('Неликвиды по возрасту')->assertSee('Дни до обнуления');

    config(['analytics.features.dead_stock' => false]);
    $this->get('/dashboards/stock')->assertOk()
        ->assertDontSee('Неликвиды по возрасту')->assertSee('Дни до обнуления');

    config(['analytics.features.dead_stock' => true, 'analytics.features.stockout_risk' => false]);
    $this->get('/dashboards/stock')->assertOk()
        ->assertSee('Неликвиды по возрасту')->assertDontSee('Дни до обнуления');
});

it('draws a horizontal top-N chart of revenue on top-products', function () {
    chartSeed('revenue', 'product', 'month:2026-08', ['a' => 300, 'b' => 100, 'c' => 200], []);

    $response = $this->get('/dashboards/top-products')->assertOk()->assertSee('<canvas', false)->assertSee("indexAxis: 'y'", false);
    $chart = $response->viewData('topChart');

    expect(array_map(fn ($p) => $p->label, $chart->series[0]->points))->toBe(['a', 'c', 'b'])
        ->and(chartValues($chart))->toBe([300.0, 200.0, 100.0]);

    config(['analytics.features.top_products' => false]);
    $this->get('/dashboards/top-products')->assertNotFound();
});

it('passes names to the chart JS only as JSON', function () {
    $name = '</script><script>alert(1)</script>';
    StagingProduct::create(['external_id' => 'x', 'name' => $name, 'synced_at' => now()]);
    chartSeed('revenue', 'product', 'month:2026-08', ['x' => 10], []);

    $html = $this->get('/dashboards/top-products')->assertOk()->getContent();

    // Блок <script> не закрывается именем: теги в JSON закодированы (JSON_HEX_TAG).
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('labels: ['.json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).']');
});

it('keeps the revenue dynamics line chart for the last 6 months on overview', function () {
    foreach (['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'] as $i => $month) {
        chartSeed('revenue', 'product', "month:{$month}", ['a' => 100 + $i], []);
    }

    $response = $this->get('/dashboards/overview')->assertOk()->assertSee('<canvas', false);

    expect($response->viewData('lineChart')->series[0]->points)->toHaveCount(6)
        ->and(substr_count($response->getContent(), "type: 'line'"))->toBe(1);
});
