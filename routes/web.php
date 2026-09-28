<?php

use App\Http\Controllers\Dashboards\AbcXyzDashboardController;
use App\Http\Controllers\Dashboards\CategoriesDashboardController;
use App\Http\Controllers\Dashboards\OverviewDashboardController;
use App\Http\Controllers\Dashboards\ProductCardController;
use App\Http\Controllers\Dashboards\SellersDashboardController;
use App\Http\Controllers\Dashboards\StockDashboardController;
use App\Http\Controllers\Dashboards\TopProductsDashboardController;
use App\Http\Controllers\Dashboards\TransfersDashboardController;
use App\Http\Controllers\Dashboards\TurnoverDashboardController;
use Illuminate\Support\Facades\Route;

// Корень — просто вход в standalone-часть: гость попадёт на /login через auth.
Route::redirect('/', '/dashboards/overview');

// Standalone-часть (StandaloneLayout на данных metrics_snapshots) — только
// для вошедших пользователей. Будущая iframe-группа сюда не входит: её
// авторизация — задача этапа Bitrix24Adapter. auth.session разлогинивает
// сессии, открытые до смены пароля (users:password).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboards/overview', OverviewDashboardController::class)->name('dashboards.overview');
    Route::get('/dashboards/abc-xyz', AbcXyzDashboardController::class)->name('dashboards.abc-xyz');

    // Флаги (analytics.features.*) скрывают только показ; метрики считаются всегда.
    // CSV-выгрузки (…/export) — под тем же флагом, что и их таблица.
    Route::get('/dashboards/stock', StockDashboardController::class)
        ->middleware('feature:dead_stock,stockout_risk')->name('dashboards.stock');
    Route::get('/dashboards/stock/export/dead-stock', [StockDashboardController::class, 'exportDeadStock'])
        ->middleware('feature:dead_stock')->name('dashboards.stock.export.dead-stock');
    Route::get('/dashboards/stock/export/stockout-risk', [StockDashboardController::class, 'exportStockoutRisk'])
        ->middleware('feature:stockout_risk')->name('dashboards.stock.export.stockout-risk');
    Route::get('/dashboards/top-products', TopProductsDashboardController::class)
        ->middleware('feature:top_products')->name('dashboards.top-products');
    Route::get('/dashboards/top-products/export', [TopProductsDashboardController::class, 'export'])
        ->middleware('feature:top_products')->name('dashboards.top-products.export');
    Route::get('/dashboards/categories', CategoriesDashboardController::class)
        ->middleware('feature:categories')->name('dashboards.categories');
    Route::get('/dashboards/categories/export', [CategoriesDashboardController::class, 'export'])
        ->middleware('feature:categories')->name('dashboards.categories.export');
    Route::get('/dashboards/turnover', TurnoverDashboardController::class)
        ->middleware('feature:turnover')->name('dashboards.turnover');
    Route::get('/dashboards/turnover/export', [TurnoverDashboardController::class, 'export'])
        ->middleware('feature:turnover')->name('dashboards.turnover.export');
    Route::get('/dashboards/transfers', TransfersDashboardController::class)
        ->middleware('feature:transfers')->name('dashboards.transfers');
    Route::get('/dashboards/transfers/export', [TransfersDashboardController::class, 'export'])
        ->middleware('feature:transfers')->name('dashboards.transfers.export');
    Route::get('/dashboards/sellers', SellersDashboardController::class)
        ->middleware('feature:sellers')->name('dashboards.sellers');
    Route::get('/dashboards/sellers/export', [SellersDashboardController::class, 'export'])
        ->middleware('feature:sellers')->name('dashboards.sellers.export');
    Route::get('/dashboards/products/{product}', ProductCardController::class)
        ->middleware('feature:product_card')->name('dashboards.product');
});
