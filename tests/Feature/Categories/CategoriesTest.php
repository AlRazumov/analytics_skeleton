<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/** @param array<string, float> $values categoryId => revenue */
function categorySeed(string $period, array $values, int $productsSold = 1): void
{
    (new EloquentMetricsSnapshotWriter)->write(array_map(
        fn ($id, $value) => new MetricsSnapshotRecord('category', (string) $id, 'revenue', (float) $value, $period, ['products_sold' => $productsSold]),
        array_keys($values),
        array_values($values),
    ));
}

it('makes category revenue add up to product revenue on the mock, month by month', function () {
    $records = collect((new MetricsCalculationService)->calculate(
        new MockAdapter(MockDataProfile::Small, 42),
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    ))->where('metricKey', 'revenue');

    $byCategory = $records->where('entityType', 'category');
    $byProduct = $records->where('entityType', 'product');

    expect($byCategory->pluck('entityId')->unique()->sort()->values()->all())
        ->toBe(['Игрушки', 'Одежда', 'Продукты', 'Товары для дома', 'Электроника']);
    foreach (['month:2026-06', 'month:2026-07', 'month:2026-08'] as $period) {
        expect($byCategory->where('period', $period)->sum('value'))
            ->toEqualWithDelta($byProduct->where('period', $period)->sum('value'), 0.01)
            ->and($byCategory->where('period', $period)->sum(fn ($r) => $r->valueMeta['products_sold']))
            ->toBe($byProduct->where('period', $period)->count());
    }
});

it('shows categories by revenue with shares, previous-month deltas and products sold', function () {
    categorySeed('month:2026-07', ['Дом' => 100, 'Игрушки' => 50]);
    categorySeed('month:2026-08', ['Дом' => 150, 'Игрушки' => 30, CategoryRevenueCalculator::NO_CATEGORY => 20], 4);

    $response = $this->get('/dashboards/categories')->assertOk()
        ->assertSee('Выручка по категориям')->assertSee('Без категории')->assertSee('Прошлый месяц')
        ->assertDontSee('с тем же месяцем прошлого года');

    $rows = $response->viewData('table')->rows;
    expect(array_map(fn ($r) => $r->categoryName, $rows))->toBe(['Дом', 'Игрушки', 'Без категории'])
        ->and($rows[0]->sharePct)->toBe(75.0)
        ->and($rows[0]->baseValue)->toBe(100.0)
        ->and($rows[0]->deltaPct)->toBe(50.0)
        ->and($rows[2]->baseValue)->toBeNull()
        ->and($rows[0]->productsSold)->toBe(4);

    $monthly = $response->viewData('monthlyChart');
    $points = $monthly->series[0]->points;
    expect(array_map(fn ($s) => $s->name, $monthly->series))->toBe(['Дом', 'Игрушки', 'Без категории'])
        ->and($points)->toHaveCount(12)
        ->and($points[0]->label)->toBe('2025-09')
        ->and($points[11]->label)->toBe('2026-08')
        ->and($points[11]->value)->toBe(150.0)
        ->and($points[10]->value)->toBe(100.0)
        ->and($points[0]->value)->toBe(0.0);
});

it('compares with the same month of last year on request and keeps ?period in links', function () {
    categorySeed('month:2025-07', ['Дом' => 40]);
    categorySeed('month:2026-07', ['Дом' => 100]);
    categorySeed('month:2026-08', ['Дом' => 150]);

    $this->get('/dashboards/categories?period=month:2026-07')->assertOk()
        ->assertSee('/dashboards/categories?period=month%3A2026-07&amp;base=year_ago', false);

    $response = $this->get('/dashboards/categories?period=month:2026-07&base=year_ago')->assertOk()
        ->assertSee('Тот же месяц год назад');
    expect($response->viewData('table')->rows[0]->baseValue)->toBe(40.0);

    $this->get('/dashboards/categories?base=year_ago')->assertOk()->assertSee('Нет данных за прошлый год');
});

it('renders the empty state without snapshots and rejects bad query values', function () {
    $this->get('/dashboards/categories')->assertOk()->assertSee('Нет данных')->assertDontSee('<canvas', false);
    $this->get('/dashboards/categories?base=nope')->assertNotFound();
    $this->get('/dashboards/categories?period=bad')->assertNotFound();
});

it('does not touch the data source', function () {
    categorySeed('month:2026-08', ['Дом' => 150]);
    $this->app->instance(DataSourceAdapter::class, throwingAdapter());

    $this->get('/dashboards/categories')->assertOk();
});

it('is hidden by the categories flag', function () {
    config(['analytics.features.categories' => false]);

    $this->get('/dashboards/categories')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('>Категории<', false);
});
