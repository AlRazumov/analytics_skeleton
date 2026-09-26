<?php

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Staging\StagingProduct;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRevenue(array $byPeriod): void
{
    $records = [];
    foreach ($byPeriod as $period => $values) {
        foreach ($values as $id => $value) {
            $records[] = new MetricsSnapshotRecord('product', (string) $id, 'revenue', (float) $value, $period);
        }
    }
    (new EloquentMetricsSnapshotWriter)->write($records);
}

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('shows an empty state without snapshots', function () {
    $this->get('/dashboards/overview')->assertOk()->assertSee('Нет данных: расчёт метрик ещё не выполнялся');
});

it('ends the window at the latest revenue period, whatever it is', function () {
    seedRevenue(['month:2026-03' => ['a' => 1], 'month:2026-08' => ['a' => 5]]);

    $response = $this->get('/dashboards/overview')->assertOk()->assertSee('по 2026-08');
    expect($response->viewData('table')->rows)->toHaveCount(2);

    seedRevenue(['month:2027-02' => ['a' => 7]]);
    $this->get('/dashboards/overview')->assertOk()->assertSee('по 2027-02');
});

it('covers exactly six months ending at the period', function () {
    seedRevenue(['month:2026-02' => ['a' => 1], 'month:2026-03' => ['a' => 1], 'month:2026-08' => ['a' => 1]]);

    $periods = collect($this->get('/dashboards/overview')->viewData('table')->rows)->pluck(1)->all();

    expect($periods)->toEqual(['2026-03', '2026-08']);
});

it('accepts ?period=month:YYYY-MM as the end of the window', function () {
    seedRevenue(['month:2026-05' => ['a' => 1], 'month:2026-08' => ['a' => 5]]);

    $this->get('/dashboards/overview?period=month:2026-05')->assertOk()->assertSee('по 2026-05');
    $this->get('/dashboards/overview?period=month:2020-01')->assertOk()->assertSee('по 2020-01');
});

it('omits the year-ago series and explains it when there is no history a year back', function () {
    seedRevenue(['month:2026-08' => ['a' => 5]]);

    $response = $this->get('/dashboards/overview')->assertOk()
        ->assertSee('Сравнение с прошлым годом недоступно');
    expect($response->viewData('barChart')->series)->toHaveCount(1);
});

it('shows the year-ago series when history covers the previous year', function () {
    seedRevenue(['month:2025-07' => ['a' => 3], 'month:2026-08' => ['a' => 5]]);

    $response = $this->get('/dashboards/overview')->assertOk()
        ->assertDontSee('Сравнение с прошлым годом недоступно');
    $series = $response->viewData('barChart')->series;
    expect($series)->toHaveCount(2)
        ->and($series[1]->name)->toBe('2025-03')
        ->and(array_map(fn ($p) => $p->value, $series[1]->points))->toBe([0.0, 0.0, 0.0, 0.0, 3.0, 0.0]);
});

it('formats KPI and table numbers like the rest of the UI', function () {
    seedRevenue(['month:2026-07' => ['a' => 1000], 'month:2026-08' => ['a' => 1234567.891]]);

    $this->get('/dashboards/overview')->assertOk()
        ->assertSee('1 235 567,89')
        ->assertSee('1 234 567,89')
        ->assertDontSee('1,234,567.89');
});

it('rejects invalid ?period with 404', function (string $bad) {
    $this->get('/dashboards/overview?period='.$bad)->assertNotFound();
})->with(['garbage', 'month:2026-13', 'week:2026-W10', 'year:2026', '']);

it('shows product names from the reference, falls back to the id and escapes markup', function () {
    StagingProduct::create(['external_id' => 'p1', 'name' => '<u>Товар</u> один', 'synced_at' => now()]);
    seedRevenue(['month:2026-08' => ['p1' => 5, 'p-unknown' => 3]]);

    $this->get('/dashboards/overview')->assertOk()
        ->assertSee('&lt;u&gt;Товар&lt;/u&gt; один', false)->assertDontSee('<u>Товар</u>', false)
        ->assertSee('p-unknown');
});

it('renders with an adapter that throws on every fetch', function () {
    seedRevenue(['month:2026-08' => ['a' => 5]]);
    app()->instance(DataSourceAdapter::class, throwingAdapter());

    $this->get('/dashboards/overview')->assertOk();
});

it('does not move the ABC/XYZ latest period when an earlier month is recalculated', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-06:2026-08'])->assertExitCode(0);
    $repository = new EloquentMetricsSnapshotRepository;
    $before = $repository->latestPeriodFor('product', 'abc_xyz_classification');

    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-05:2026-05'])->assertExitCode(0);

    expect($before)->toBe('month:2026-08')
        ->and($repository->latestPeriodFor('product', 'abc_xyz_classification'))->toBe($before);
});
