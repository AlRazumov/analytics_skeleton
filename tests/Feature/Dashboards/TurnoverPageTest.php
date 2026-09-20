<?php

use App\Core\Domain\Period;
use App\Core\Staging\StagingProduct;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Core\Widgets\DTO\ValueRange;
use App\Models\User;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, float> $values productId => turnover */
function seedTurnover(string $period, array $values): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($id, $value) => new MetricsSnapshotRecord('product', (string) $id, 'turnover', (float) $value, $period, [
            'units_sold' => $value * 10, 'avg_stock' => 10.0, 'opening_stock' => 10.0, 'closing_stock' => 10.0 + $value,
        ]),
        array_keys($values),
        array_values($values),
    ));
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows the lowest and the highest turnover with units from value_meta', function () {
    seedTurnover('month:2026-08', ['a' => 0, 'b' => 0.5, 'c' => 1.5, 'd' => 3]);
    config(['analytics.display.table_limit' => 2]);

    $response = $this->get('/dashboards/turnover')->assertOk()
        ->assertSee('Самая низкая оборачиваемость')->assertSee('Самая высокая оборачиваемость')
        ->assertSee('Показано 2 из 4');

    expect(array_map(fn ($r) => $r->productId, $response->viewData('lowest')->rows))->toBe(['a', 'b'])
        ->and(array_map(fn ($r) => $r->productId, $response->viewData('highest')->rows))->toBe(['d', 'c']);
    $row = $response->viewData('highest')->rows[0];
    expect($row->unitsSold)->toBe(30.0)->and($row->closingStock)->toBe(13.0)->and($row->turnover)->toBe(3.0);
});

it('buckets the distribution into 0, (0;1), [1;2) and 2+ with the boundaries from the config', function () {
    seedTurnover('month:2026-08', ['z' => 0, 'l1' => 0.01, 'l2' => 0.99, 'm1' => 1, 'm2' => 1.99, 'h1' => 2, 'h2' => 5]);
    seedTurnover('month:2026-07', ['other' => 0]);

    $chart = $this->get('/dashboards/turnover')->assertOk()->viewData('distribution');

    $points = $chart->series[0]->points;
    expect(array_map(fn ($p) => $p->label, $points))->toBe(['0', '0–1', '1–2', '2+'])
        ->and(array_map(fn ($p) => $p->value, $points))->toBe([1.0, 2.0, 2.0, 2.0]);

    config(['analytics.display.turnover_bounds' => [3.0]]);
    $points = $this->get('/dashboards/turnover')->viewData('distribution')->series[0]->points;
    expect(array_map(fn ($p) => $p->label, $points))->toBe(['0', '0–3', '3+'])
        ->and(array_map(fn ($p) => $p->value, $points))->toBe([1.0, 5.0, 1.0]);
});

it('draws a canvas when there is data', function () {
    seedTurnover('month:2026-08', ['a' => 1]);

    $this->get('/dashboards/turnover')->assertOk()->assertSee('<canvas', false);
});

it('accepts ?period and rejects an invalid one with 404', function () {
    seedTurnover('month:2026-07', ['a' => 1]);
    seedTurnover('month:2026-08', ['b' => 1]);

    expect($this->get('/dashboards/turnover?period=month:2026-07')->assertOk()->viewData('lowest')->rows[0]->productId)->toBe('a');
    $this->get('/dashboards/turnover?period=garbage')->assertNotFound();
    $this->get('/dashboards/turnover?period=quarter:2026-Q3')->assertNotFound();
});

it('shows an empty state without a chart when there are no snapshots or no rows in the period', function () {
    $this->get('/dashboards/turnover')->assertOk()->assertSee('Нет данных')->assertDontSee('<canvas', false);

    seedTurnover('month:2026-08', ['a' => 1]);
    $this->get('/dashboards/turnover?period=month:2020-01')->assertOk()->assertSee('Нет данных за период')->assertDontSee('<canvas', false);
});

it('hides the page and its nav item when the turnover flag is off', function () {
    $this->get('/dashboards/overview')->assertOk()->assertSee('Оборачиваемость');

    config(['analytics.features.turnover' => false]);
    $this->get('/dashboards/turnover')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('Оборачиваемость');
});

it('requires authentication', function () {
    auth()->logout();

    $this->get('/dashboards/turnover')->assertRedirect('/login');
});

it('escapes product names in the table and in the chart data', function () {
    StagingProduct::create(['external_id' => 'x', 'name' => '<script>alert(1)</script>', 'synced_at' => now()]);
    seedTurnover('month:2026-08', ['x' => 1]);

    $this->get('/dashboards/turnover')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('counts buckets in one aggregate query with inclusive/exclusive bounds', function () {
    seedTurnover('month:2026-08', ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3]);
    seedTurnover('month:2026-07', ['e' => 1]);
    $repository = new EloquentMetricsComparisonRepository;

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $counts = $repository->bucketCounts('turnover', 'product', Period::fromKey('month:2026-08'), [
        ValueRange::exactly(0), ValueRange::open(0, 2), ValueRange::halfOpen(1, 3), ValueRange::halfOpen(1, null), ValueRange::open(3, null),
    ]);

    expect($counts)->toBe([1, 1, 2, 3, 0])->and($queries)->toBe(1)
        ->and($repository->bucketCounts('turnover', 'product', Period::fromKey('month:2026-08'), []))->toBe([])
        ->and($repository->bucketCounts('revenue', 'product', Period::fromKey('month:2026-08'), [ValueRange::halfOpen(0, null)]))->toBe([0]);
});
