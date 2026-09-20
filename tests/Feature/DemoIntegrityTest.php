<?php

use App\Core\Contracts\DataSourceAdapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DEMO_PAGES = [
    '/dashboards/overview',
    '/dashboards/abc-xyz',
    '/dashboards/stock',
    '/dashboards/top-products',
    '/dashboards/turnover',
    '/dashboards/transfers',
];

beforeEach(function () {
    config(['analytics.mock.profile' => 'small', 'analytics.mock.seed' => 1]);
    $this->artisan('demo:install')->assertExitCode(0);
    $this->actingAs(User::factory()->create());
});

it('serves every demo page with data after demo:install on Small', function () {
    foreach (DEMO_PAGES as $path) {
        $response = $this->get($path)->assertOk()->assertDontSee('Нет данных');
        if ($path !== '/dashboards/abc-xyz') { // в матрице названий товаров нет
            $response->assertSee('Product ');
        }
    }

    // Графики: canvas там, где они ожидаются.
    $canvases = fn (string $path) => substr_count($this->get($path)->getContent(), '<canvas');
    expect($canvases('/dashboards/overview'))->toBe(2)
        ->and($canvases('/dashboards/abc-xyz'))->toBe(0)
        ->and($canvases('/dashboards/stock'))->toBe(2)
        ->and($canvases('/dashboards/top-products'))->toBe(1)
        ->and($canvases('/dashboards/turnover'))->toBe(1)
        ->and($canvases('/dashboards/transfers'))->toBe(0);
});

it('fills the data behind each page', function () {
    expect($this->get('/dashboards/abc-xyz')->viewData('matrix')->cells)->not->toBeEmpty();

    $turnover = $this->get('/dashboards/turnover');
    expect($turnover->viewData('lowest')->rows)->not->toBeEmpty()
        ->and($turnover->viewData('highest')->rows)->not->toBeEmpty()
        ->and($turnover->viewData('distribution'))->not->toBeNull();

    $top = $this->get('/dashboards/top-products');
    expect($top->viewData('top')->rows)->not->toBeEmpty()
        ->and($top->viewData('topChart'))->not->toBeNull();

    expect($this->get('/dashboards/transfers')->viewData('transfers')->rows)->not->toBeEmpty();

    $stock = $this->get('/dashboards/stock');
    expect($stock->viewData('daysOfStockChart'))->not->toBeNull()
        ->and($stock->viewData('stockoutRisk')->rows)->not->toBeEmpty();
});

it('has no year-ago comparison on Small, and the page still renders for a direct visit', function () {
    $this->get('/dashboards/top-products')->assertOk()->assertDontSee('с тем же месяцем прошлого года');
    $this->get('/dashboards/top-products?base=year_ago')->assertOk()->assertSee('Нет данных за прошлый год');
});

it('renders every page from the database when the adapter throws on every fetch', function () {
    app()->instance(DataSourceAdapter::class, throwingAdapter());

    foreach (DEMO_PAGES as $path) {
        $this->get($path)->assertOk();
    }
    $this->get('/dashboards/top-products')->assertSee('Product ');
});
