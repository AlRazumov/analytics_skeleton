<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Staging\StagingSeller;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

function runSellersPagePipeline(SellerCoverage $mode): void
{
    $adapter = new MockAdapter(MockDataProfile::Small, 42, sellerCoverage: $mode);
    app(ReferenceSyncService::class)->sync($adapter);
    $records = app(MetricsCalculationService::class)->calculate(
        $adapter,
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    );
    (new EloquentMetricsSnapshotWriter)->write($records);
}

/** Разбирает CSV-ответ (без BOM) в список строк. */
function sellersCsv(string $body): array
{
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

    return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($body, 3)))));
}

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get('/dashboards/sellers')->assertRedirect('/login');
    $this->get('/dashboards/sellers/export')->assertRedirect('/login');
});

it('returns 404 and hides the nav item when the flag is off', function () {
    $this->get('/dashboards/overview')->assertOk()->assertSee('Продавцы');

    config(['analytics.features.sellers' => false]);
    $this->get('/dashboards/sellers')->assertNotFound();
    $this->get('/dashboards/sellers/export')->assertNotFound();
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('Продавцы');
});

it('shows the empty state on an empty database and has nothing to export', function () {
    $this->get('/dashboards/sellers')->assertOk()->assertSee('Нет данных')->assertDontSee('<table', false);
    $this->get('/dashboards/sellers/export')->assertNotFound();
});

it('shows every seller ranked by sales_count with all enabled metrics as columns', function () {
    runSellersPagePipeline(SellerCoverage::Full);

    $response = $this->get('/dashboards/sellers')->assertOk()
        ->assertSee('Рейтинг: Количество продаж')
        ->assertSee('Показано 11 из 11') // 12 продавцов, у уволенного в августе продаж нет
        ->assertSee('Без продавца')
        ->assertSee('Средний чек')->assertSee('Продаж в активный день')->assertSee('Изменение к пред. месяцу, %')
        ->assertSee('Скачать CSV');

    $data = $response->viewData('data');
    expect($data->period)->toBe('month:2026-08')
        ->and($data->columns)->toBe(['sales_amount', 'avg_check', 'share_of_total', 'sales_per_active_day', 'trend'])
        ->and(substr_count($response->getContent(), '<canvas'))->toBe(1);
});

it('ranks by the metric from ?metric= and keeps the period in the switcher links', function () {
    runSellersPagePipeline(SellerCoverage::Full);

    $response = $this->get('/dashboards/sellers?metric=avg_check&period=month:2026-07')->assertOk()
        ->assertSee('Рейтинг: Средний чек');

    $data = $response->viewData('data');
    $values = array_map(fn ($r) => $r->value, $data->rows);
    $sorted = $values;
    rsort($sorted);
    expect($data->metric)->toBe('avg_check')->and($data->period)->toBe('month:2026-07')->and($values)->toBe($sorted);

    $response->assertSee(e(route('dashboards.sellers', ['period' => 'month:2026-07', 'metric' => 'sales_count'])), false)
        ->assertSee(e(route('dashboards.sellers.export', ['period' => 'month:2026-07', 'metric' => 'avg_check'])), false);
});

it('returns 404 for an unknown, disabled or malformed metric and a bad period', function () {
    runSellersPagePipeline(SellerCoverage::Full);

    $this->get('/dashboards/sellers?metric=revenue')->assertNotFound();
    $this->get('/dashboards/sellers?metric[]=sales_count')->assertNotFound();
    $this->get('/dashboards/sellers?period=2026-08')->assertNotFound();
    $this->get('/dashboards/sellers/export?metric=nope')->assertNotFound();

    config(['analytics.enabled_metrics.seller' => ['sales_count', 'sales_amount']]);
    $this->get('/dashboards/sellers?metric=avg_check')->assertNotFound();
    $this->get('/dashboards/sellers')->assertOk()->assertDontSee('Средний чек');
});

it('falls back to the first enabled metric when sales_count is disabled', function () {
    runSellersPagePipeline(SellerCoverage::Full);
    config(['analytics.enabled_metrics.seller' => ['sales_amount', 'avg_check']]);

    expect($this->get('/dashboards/sellers')->assertOk()->viewData('data')->metric)->toBe('sales_amount');
});

it('shows the coverage notice in partial mode', function () {
    runSellersPagePipeline(SellerCoverage::Partial);

    $this->get('/dashboards/sellers')->assertOk()->assertSee('покрывают')->assertSee('Скачать CSV');
});

it('shows the empty state with an explanation in none mode', function () {
    runSellersPagePipeline(SellerCoverage::None);

    $this->get('/dashboards/sellers')->assertOk()
        ->assertSee('Нет данных')->assertSee('не передаёт продавца')->assertDontSee('<table', false)->assertDontSee('Скачать CSV');
    $this->get('/dashboards/sellers/export')->assertNotFound();
});

it('exports the same table as CSV for Excel', function () {
    runSellersPagePipeline(SellerCoverage::Full);

    $response = $this->get('/dashboards/sellers/export?metric=sales_amount')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="sellers-sales_amount-2026-08.csv"');

    $rows = sellersCsv($response->getContent());
    $page = $this->get('/dashboards/sellers?metric=sales_amount')->viewData('data');

    expect($rows[0])->toBe(['#', 'Название', 'Идентификатор', 'Сумма продаж', 'Количество продаж', 'Средний чек', 'Доля, %', 'Продаж в активный день', 'Изменение к пред. месяцу, %'])
        ->and(count($rows))->toBe(1 + count($page->rows) + 1)
        ->and(array_map(fn ($r) => $r[2], array_slice($rows, 1, count($page->rows))))->toBe(array_map(fn ($r) => $r->id, $page->rows))
        ->and($rows[1][0])->toBe('1')
        ->and($rows[1][3])->toBe(number_format($page->rows[0]->value, 2, ',', ''))
        ->and($rows[1][4])->toMatch('/^\d+$/')
        ->and(end($rows)[0])->toBe('—')->and(end($rows)[1])->toBe('Без продавца');
});

it('escapes seller names on the page and keeps them intact in the CSV', function () {
    config(['analytics.enabled_metrics.seller' => ['sales_count']]);
    StagingSeller::create(['external_id' => 's1', 'name' => '<script>alert(1)</script>; "Иванов"', 'is_active' => true, 'synced_at' => now()]);
    (new EloquentMetricsSnapshotWriter)->write([
        new MetricsSnapshotRecord('seller', 's1', 'sales_count', 5, 'month:2026-08'),
    ]);

    $this->get('/dashboards/sellers')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

    $rows = sellersCsv($this->get('/dashboards/sellers/export')->assertOk()->getContent());
    expect($rows[1])->toBe(['1', '<script>alert(1)</script>; "Иванов"', 's1', '5']);
});
