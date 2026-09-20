<?php

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingWarehouse;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @param array<string, array{float, float}> $pairs "product:wh" => [stock, rate] */
function seedTransferDays(array $pairs, string $period = 'month:2026-08'): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($key, $d) => new MetricsSnapshotRecord('product_warehouse', $key, 'days_of_stock', $d[0] / $d[1], $period, ['stock_qty' => $d[0], 'daily_rate' => $d[1]]),
        array_keys($pairs),
        array_values($pairs),
    ));
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get('/dashboards/transfers')->assertRedirect('/login');
});

it('returns 404 and hides the nav item when the flag is off', function () {
    $this->get('/dashboards/overview')->assertOk()->assertSee('Перемещения');

    config(['analytics.features.transfers' => false]);
    $this->get('/dashboards/transfers')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('Перемещения');
});

it('shows the empty state on an empty database', function () {
    $this->get('/dashboards/transfers')->assertOk()->assertSee('Нет данных')->assertDontSee('<table', false);
});

it('shows the summary, the table and the rules', function () {
    StagingProduct::create(['external_id' => 'a', 'name' => 'Болт', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w1', 'name' => 'Центральный', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w2', 'name' => 'Северный', 'synced_at' => now()]);
    seedTransferDays([
        'a:w1' => [10, 5], 'a:w2' => [1000, 5],   // рекомендация: 140 шт. со w2 на w1
        'b:w1' => [5, 5],                         // дефицит без донора
    ]);

    $response = $this->get('/dashboards/transfers')->assertOk()
        ->assertSee('Дефицитных пар (товар × склад): 2', false)
        ->assertSee('Для скольких есть рекомендация: 1')->assertSee('Без донора: 1')
        ->assertSee('Показано 1 из 1')
        ->assertSee('Болт')->assertSee('Северный')->assertSee('Центральный')
        ->assertSee('2 → 30')->assertSee('200 → 172')
        ->assertSee('не учитывает сроки доставки и сезонность');

    $row = $response->viewData('transfers')->rows[0];
    expect($row->quantity)->toBe(140)->and($row->fromWarehouseId)->toBe('w2')->and($row->toWarehouseId)->toBe('w1');
});

it('falls back to ids when the reference books are empty', function () {
    seedTransferDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]]);

    $this->get('/dashboards/transfers')->assertOk()->assertSee('<td>a</td>', false)->assertSee('<td>w2</td>', false)->assertSee('<td>w1</td>', false);
});

it('escapes product and warehouse names', function () {
    StagingProduct::create(['external_id' => 'a', 'name' => '<script>alert(1)</script>', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w1', 'name' => '<b>склад</b>', 'synced_at' => now()]);
    seedTransferDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]]);

    $this->get('/dashboards/transfers')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>склад</b>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('&lt;b&gt;склад&lt;/b&gt;', false);
});

it('accepts ?period, rejects an invalid one and shows an empty state for a missing one', function () {
    seedTransferDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]], 'month:2026-07');
    seedTransferDays(['b:w1' => [1000, 5]], 'month:2026-08');

    // по умолчанию — последний период (08): рекомендаций нет
    expect($this->get('/dashboards/transfers')->assertOk()->viewData('transfers')->total)->toBe(0);
    expect($this->get('/dashboards/transfers?period=month:2026-07')->assertOk()->viewData('transfers')->total)->toBe(1);
    $this->get('/dashboards/transfers?period=garbage')->assertNotFound();
    $this->get('/dashboards/transfers?period=quarter:2026-Q3')->assertNotFound();
    $this->get('/dashboards/transfers?period=month:2020-01')->assertOk()->assertSee('Нет данных за период');
});

it('limits the rows by display.table_limit and reports the total', function () {
    seedTransferDays(['a:w1' => [10, 5], 'a:w2' => [10000, 5], 'b:w1' => [10, 5], 'b:w2' => [10000, 5], 'c:w1' => [10, 5], 'c:w2' => [10000, 5]]);
    config(['analytics.display.table_limit' => 2]);

    $this->get('/dashboards/transfers')->assertOk()->assertSee('Показано 2 из 3');
});

it('renders without touching the adapter', function () {
    app()->instance(DataSourceAdapter::class, throwingAdapter());
    seedTransferDays(['a:w1' => [10, 5], 'a:w2' => [1000, 5]]);

    $this->get('/dashboards/transfers')->assertOk()->assertSee('Показано 1 из 1');
});

it('does not run more queries for more rows (no N+1)', function () {
    $count = function (int $products) {
        DB::table('metrics_snapshots')->delete();
        $pairs = [];
        foreach (range(1, $products) as $i) {
            $pairs["p{$i}:w1"] = [10, 5];
            $pairs["p{$i}:w2"] = [10000, 5];
        }
        seedTransferDays($pairs);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->get('/dashboards/transfers')->assertOk();

        return $queries;
    };

    $few = $count(2);
    expect($count(30))->toBe($few);
});
