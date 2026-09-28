<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Domain\DateRange;
use App\Core\Domain\Period;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsComparisonRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/** Полный расчёт на Small-моке за июнь–август 2026. */
function runMonthSwitcherPipeline(): void
{
    $adapter = new MockAdapter(MockDataProfile::Small, 42);
    app(ReferenceSyncService::class)->sync($adapter);
    (new EloquentMetricsSnapshotWriter)->write(app(MetricsCalculationService::class)->calculate(
        $adapter,
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    ));
}

/** Ссылка переключателя на месяц $month с остальными параметрами $query страницы $path (period — на своём месте, первым). */
function switcherLink(string $path, string $month, array $query = []): string
{
    return e(url($path).'?'.http_build_query(['period' => "month:{$month}", ...$query]));
}

it('finds the nearest months with data of the given entity and metric, skipping gaps', function () {
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 1.0, 'month:2026-03'),
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 1.0, 'month:2026-05'),
        new MetricsSnapshotRecord('product', 'p1', 'revenue', 1.0, 'month:2026-08'),
        // Та же metric_key у другой сущности — не в счёт.
        new MetricsSnapshotRecord('category', 'c1', 'revenue', 1.0, 'month:2026-06'),
        new MetricsSnapshotRecord('product', 'p1', 'turnover', 1.0, 'month:2026-07'),
    ]);
    $repository = new EloquentMetricsComparisonRepository;
    $keys = fn (array $pair) => array_map(fn (?Period $p) => $p?->key(), $pair);

    expect($keys($repository->adjacentPeriods([['product', 'revenue']], Period::fromKey('month:2026-05'))))->toBe(['month:2026-03', 'month:2026-08'])
        ->and($keys($repository->adjacentPeriods([['product', 'revenue']], Period::fromKey('month:2026-03'))))->toBe([null, 'month:2026-05'])
        ->and($keys($repository->adjacentPeriods([['product', 'revenue']], Period::fromKey('month:2026-08'))))->toBe(['month:2026-05', null])
        ->and($keys($repository->adjacentPeriods([['product', 'revenue'], ['product', 'turnover']], Period::fromKey('month:2026-08'))))->toBe(['month:2026-07', null])
        ->and($keys($repository->adjacentPeriods([['category', 'revenue']], Period::fromKey('month:2026-08'))))->toBe(['month:2026-06', null]);
});

it('shows the previous month on the latest month and the next one on the earliest, on every page with a month', function (string $path) {
    runMonthSwitcherPipeline();

    $this->get($path)->assertOk()
        ->assertSee('<strong>2026-08</strong>', false)
        ->assertSee(switcherLink($path, '2026-07'), false)
        ->assertDontSee('rel="next"', false);

    $this->get($path.'?period=month:2026-06')->assertOk()
        ->assertSee('<strong>2026-06</strong>', false)
        ->assertSee(switcherLink($path, '2026-07'), false)
        ->assertDontSee('rel="prev"', false);
})->with([
    '/dashboards/overview',
    '/dashboards/top-products',
    '/dashboards/categories',
    '/dashboards/stock',
    '/dashboards/turnover',
    '/dashboards/transfers',
    '/dashboards/sellers',
]);

it('keeps the other page parameters in the switcher links', function () {
    runMonthSwitcherPipeline();

    $this->get('/dashboards/top-products?period=month:2026-07&base=year_ago&category=Одежда')->assertOk()
        ->assertSee(switcherLink('/dashboards/top-products', '2026-06', ['base' => 'year_ago', 'category' => 'Одежда']), false)
        ->assertSee(switcherLink('/dashboards/top-products', '2026-08', ['base' => 'year_ago', 'category' => 'Одежда']), false);

    $this->get('/dashboards/sellers?period=month:2026-07&metric=avg_check')->assertOk()
        ->assertSee(switcherLink('/dashboards/sellers', '2026-08', ['metric' => 'avg_check']), false);
});

it('has no switcher on the ABC/XYZ page and when there is no data', function () {
    $this->get('/dashboards/top-products')->assertOk()->assertDontSee('class="month-switcher"', false);
    $this->get('/dashboards/stock')->assertOk()->assertDontSee('class="month-switcher"', false);

    runMonthSwitcherPipeline();
    $this->get('/dashboards/abc-xyz')->assertOk()->assertDontSee('class="month-switcher"', false);
});
