<?php

use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, float> $values productId => revenue */
function baseSeed(string $period, array $values): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($id, $value) => new MetricsSnapshotRecord('product', (string) $id, 'revenue', (float) $value, $period),
        array_keys($values),
        array_values($values),
    ));
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('compares with the previous month by default and hides the switch without last-year data', function () {
    baseSeed('month:2026-07', ['a' => 100]);
    baseSeed('month:2026-08', ['a' => 150]);

    $response = $this->get('/dashboards/top-products')->assertOk()
        ->assertSee('Прошлый месяц')->assertSee('к пред. месяцу')
        ->assertDontSee('с тем же месяцем прошлого года')->assertDontSee('к тому же месяцу прошлого года');

    expect($response->viewData('top')->rows[0]->baseValue)->toBe(100.0);
});

it('offers the year-ago base and compares with the same month of the previous year', function () {
    baseSeed('month:2025-08', ['a' => 60, 'b' => 10]);
    baseSeed('month:2026-07', ['a' => 100, 'b' => 100]);
    baseSeed('month:2026-08', ['a' => 150, 'b' => 20]);

    $this->get('/dashboards/top-products')->assertOk()->assertSee('с тем же месяцем прошлого года');

    $response = $this->get('/dashboards/top-products?base=year_ago')->assertOk()
        ->assertSee('Тот же месяц год назад')->assertSee('к тому же месяцу прошлого года')
        ->assertDontSee('к пред. месяцу')->assertDontSee('Нет данных за прошлый год');

    $rows = collect($response->viewData('top')->rows)->keyBy('productId');
    expect($rows['a']->baseValue)->toBe(60.0)->and($rows['a']->deltaAbs)->toBe(90.0)
        ->and($rows['b']->baseValue)->toBe(10.0);
    expect($response->viewData('antiTop')->rows[0]->productId)->toBe('b');
});

it('keeps ?period in the switch links', function () {
    baseSeed('month:2025-07', ['a' => 60]);
    baseSeed('month:2026-07', ['a' => 100]);
    baseSeed('month:2026-08', ['a' => 150]);

    $this->get('/dashboards/top-products?period=month:2026-07')->assertOk()
        ->assertSee('/dashboards/top-products?period=month%3A2026-07&amp;base=year_ago', false);
});

it('explains a missing last-year base on a direct visit, without errors or tables', function () {
    baseSeed('month:2026-07', ['a' => 100]);
    baseSeed('month:2026-08', ['a' => 150]);

    $this->get('/dashboards/top-products?base=year_ago')->assertOk()
        ->assertSee('Нет данных за прошлый год')
        ->assertDontSee('Показано')->assertDontSee('<canvas', false)
        ->assertSee('Показать сравнение с предыдущим месяцем')
        ->assertDontSee('с тем же месяцем прошлого года</a>', false);
});

it('renders the empty state, not the missing-base message, when there are no snapshots at all', function () {
    $this->get('/dashboards/top-products?base=year_ago')->assertOk()
        ->assertSee('Нет данных')->assertDontSee('Нет данных за прошлый год');
});

it('rejects an invalid base with 404', function (string $query) {
    baseSeed('month:2026-08', ['a' => 1]);

    $this->get('/dashboards/top-products?'.$query)->assertNotFound();
})->with(['base=yesterday', 'base=', 'base[]=year_ago', 'base=YEAR_AGO']);
