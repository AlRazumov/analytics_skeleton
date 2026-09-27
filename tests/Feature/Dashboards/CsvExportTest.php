<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const CSV_EXPORTS = [
    '/dashboards/top-products/export',
    '/dashboards/categories/export',
    '/dashboards/stock/export/dead-stock',
    '/dashboards/stock/export/stockout-risk',
    '/dashboards/turnover/export',
    '/dashboards/transfers/export',
];

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/** Полный расчёт на Small-моке за июнь–август 2026. */
function runCsvExportPipeline(): void
{
    $adapter = new MockAdapter(MockDataProfile::Small, 42);
    app(ReferenceSyncService::class)->sync($adapter);
    $records = app(MetricsCalculationService::class)->calculate(
        $adapter,
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    );
    (new EloquentMetricsSnapshotWriter)->write($records);
}

/**
 * Разбирает CSV-ответ (без BOM) в строки; проверяет заголовки ответа.
 *
 * @return list<list<string>>
 */
function exportCsv($test, string $url, string $filename): array
{
    $body = $test->get($url)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
        ->getContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

    return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($body, 3)))));
}

function csvNumber(string $cell): float
{
    return (float) str_replace(',', '.', $cell);
}

it('redirects guests to the login page', function () {
    auth()->logout();

    foreach (CSV_EXPORTS as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

it('has nothing to export before the first calculation', function () {
    foreach (CSV_EXPORTS as $url) {
        $this->get($url)->assertNotFound();
    }
});

it('hides each export behind the flag of its table', function () {
    runCsvExportPipeline();

    config([
        'analytics.features.top_products' => false,
        'analytics.features.categories' => false,
        'analytics.features.dead_stock' => false,
        'analytics.features.turnover' => false,
        'analytics.features.transfers' => false,
    ]);
    foreach (CSV_EXPORTS as $url) {
        $url === '/dashboards/stock/export/stockout-risk'
            ? $this->get($url)->assertOk()
            : $this->get($url)->assertNotFound();
    }

    config(['analytics.features.stockout_risk' => false]);
    $this->get('/dashboards/stock/export/stockout-risk')->assertNotFound();
});

it('rejects a bad period or base like the pages do', function () {
    runCsvExportPipeline();

    foreach (CSV_EXPORTS as $url) {
        $this->get($url.'?period=2026-08')->assertNotFound();
    }
    $this->get('/dashboards/top-products/export?base=nope')->assertNotFound();
    $this->get('/dashboards/categories/export?base[]=previous')->assertNotFound();
});

it('exports every product with revenue, not only the page limit', function () {
    runCsvExportPipeline();
    config(['analytics.display.table_limit' => 3]);

    $page = $this->get('/dashboards/top-products')->assertOk()
        ->assertSee(e(route('dashboards.top-products.export', ['period' => 'month:2026-08'])), false)
        ->viewData('top');
    $rows = exportCsv($this, '/dashboards/top-products/export', 'top-products-2026-08.csv');

    $values = array_map(fn ($r) => csvNumber($r[3]), array_slice($rows, 1));
    $sorted = $values;
    rsort($sorted);

    expect($rows[0])->toBe(['#', 'Товар', 'Идентификатор', 'Выручка', 'Прошлый месяц', 'Изменение, к пред. месяцу', 'Изменение, % к пред. месяцу'])
        ->and(count($rows) - 1)->toBe($page->total)->and($page->total)->toBeGreaterThan(3)
        ->and(array_column(array_slice($rows, 1, 3), 2))->toBe(array_map(fn ($r) => $r->productId, $page->rows))
        ->and($rows[1][0])->toBe('1')->and($rows[1][1])->toBe($page->rows[0]->productName)
        ->and($rows[1][3])->toBe(number_format($page->rows[0]->value, 2, ',', ''))
        ->and($values)->toBe($sorted);
});

it('keeps the comparison base in the top products export', function () {
    runCsvExportPipeline();

    $rows = exportCsv($this, '/dashboards/top-products/export?period=month:2026-08&base=year_ago', 'top-products-2026-08-year-ago.csv');

    // На трёх месяцах истории базы «год назад» нет — колонки пустые.
    expect($rows[0][4])->toBe('Тот же месяц год назад')
        ->and(array_unique(array_column(array_slice($rows, 1), 4)))->toBe(['']);
});

it('exports the categories table', function () {
    runCsvExportPipeline();

    $page = $this->get('/dashboards/categories?period=month:2026-07')->assertOk()
        ->assertSee(e(route('dashboards.categories.export', ['period' => 'month:2026-07'])), false)
        ->viewData('table');
    $rows = exportCsv($this, '/dashboards/categories/export?period=month:2026-07', 'categories-2026-07.csv');

    expect($rows[0])->toBe(['#', 'Категория', 'Идентификатор', 'Выручка', 'Доля, %', 'Прошлый месяц', 'Изменение, к пред. месяцу', 'Изменение, % к пред. месяцу', 'Продано товаров'])
        ->and(array_column(array_slice($rows, 1), 2))->toBe(array_map(fn ($r) => $r->categoryId, $page->rows))
        ->and($rows[1][4])->toMatch('/^\d+,\d$/')
        ->and($rows[1][8])->toMatch('/^\d+$/');
});

it('exports every dead stock product and every stockout risk pair within the display thresholds', function () {
    runCsvExportPipeline();
    config(['analytics.display.table_limit' => 1]);

    $page = $this->get('/dashboards/stock')->assertOk()
        ->assertSee(e(route('dashboards.stock.export.dead-stock', ['period' => 'month:2026-08'])), false)
        ->assertSee(e(route('dashboards.stock.export.stockout-risk', ['period' => 'month:2026-08'])), false);

    $dead = exportCsv($this, '/dashboards/stock/export/dead-stock', 'dead-stock-2026-08.csv');
    expect($dead[0])->toBe(['#', 'Товар', 'Идентификатор', 'Остаток, шт.', 'Дней без продаж', 'Без продаж за весь просмотренный период'])
        ->and(count($dead) - 1)->toBe($page->viewData('deadStock')->total)
        ->and(min(array_map(fn ($r) => csvNumber($r[4]), array_slice($dead, 1))))->toBeGreaterThanOrEqual(90.0)
        ->and(array_diff(array_column(array_slice($dead, 1), 5), ['да', 'нет']))->toBe([]);

    $risk = exportCsv($this, '/dashboards/stock/export/stockout-risk', 'stockout-risk-2026-08.csv');
    $days = array_map(fn ($r) => csvNumber($r[7]), array_slice($risk, 1));
    $sorted = $days;
    sort($sorted);
    expect($risk[0])->toBe(['#', 'Товар', 'Идентификатор товара', 'Склад', 'Идентификатор склада', 'Остаток, шт.', 'Продаж в день, шт.', 'Дней до обнуления'])
        ->and(count($risk) - 1)->toBe($page->viewData('stockoutRisk')->total)->and(count($risk) - 1)->toBeGreaterThan(1)
        ->and(max($days))->toBeLessThanOrEqual(14.0)
        ->and($days)->toBe($sorted)
        ->and($risk[1][3])->toStartWith('Склад');
});

it('exports every product turnover in ascending order', function () {
    runCsvExportPipeline();
    config(['analytics.display.table_limit' => 3]);

    $page = $this->get('/dashboards/turnover')->assertOk()
        ->assertSee(e(route('dashboards.turnover.export', ['period' => 'month:2026-08'])), false)
        ->viewData('lowest');
    $rows = exportCsv($this, '/dashboards/turnover/export', 'turnover-2026-08.csv');

    $values = array_map(fn ($r) => csvNumber($r[5]), array_slice($rows, 1));
    $sorted = $values;
    sort($sorted);
    expect($rows[0])->toBe(['#', 'Товар', 'Идентификатор', 'Остаток на конец месяца, шт.', 'Продано, шт.', 'Оборачиваемость'])
        ->and(count($rows) - 1)->toBe($page->total)->and($page->total)->toBeGreaterThan(3)
        ->and(array_column(array_slice($rows, 1, 3), 2))->toBe(array_map(fn ($r) => $r->productId, $page->rows))
        ->and($values)->toBe($sorted);
});

it('exports every transfer recommendation, with an empty cell for the infinite coverage of a no-sales donor', function () {
    runCsvExportPipeline();
    config(['analytics.display.table_limit' => 1]);

    $page = $this->get('/dashboards/transfers')->assertOk()
        ->assertSee(e(route('dashboards.transfers.export', ['period' => 'month:2026-08'])), false)
        ->viewData('transfers');
    $rows = exportCsv($this, '/dashboards/transfers/export', 'transfers-2026-08.csv');

    expect($rows[0])->toHaveCount(14)->and($rows[0][5])->toBe('Донор')
        ->and(count($rows) - 1)->toBe($page->total)->and($page->total)->toBeGreaterThan(1)
        ->and($rows[1][1])->toBe($page->rows[0]->productName)
        ->and($rows[1][8])->toMatch('/^\d+$/');

    $surplus = array_values(array_filter(array_slice($rows, 1), fn ($r) => $r[5] === 'по остатку (без продаж)'));
    expect($surplus)->not->toBeEmpty()
        ->and(array_unique(array_merge(array_column($surplus, 9), array_column($surplus, 10))))->toBe(['']);
});

it('exports from the database without touching the data source', function () {
    runCsvExportPipeline();
    app()->instance(DataSourceAdapter::class, throwingAdapter());

    foreach (CSV_EXPORTS as $url) {
        $this->get($url)->assertOk();
    }
});
