<?php

use App\Http\Controllers\Dashboards\AbcXyzDashboardController;
use App\Http\Controllers\Dashboards\OverviewDashboardController;
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
// авторизация — задача этапа Bitrix24Adapter.
Route::middleware('auth')->group(function () {
    Route::get('/dashboards/overview', OverviewDashboardController::class)->name('dashboards.overview');
    Route::get('/dashboards/abc-xyz', AbcXyzDashboardController::class)->name('dashboards.abc-xyz');

    // Флаги (analytics.features.*) скрывают только показ; метрики считаются всегда.
    Route::get('/dashboards/stock', StockDashboardController::class)
        ->middleware('feature:dead_stock,stockout_risk')->name('dashboards.stock');
    Route::get('/dashboards/top-products', TopProductsDashboardController::class)
        ->middleware('feature:top_products')->name('dashboards.top-products');
    Route::get('/dashboards/turnover', TurnoverDashboardController::class)
        ->middleware('feature:turnover')->name('dashboards.turnover');
    Route::get('/dashboards/transfers', TransfersDashboardController::class)
        ->middleware('feature:transfers')->name('dashboards.transfers');
    Route::get('/dashboards/sellers', SellersDashboardController::class)
        ->middleware('feature:sellers')->name('dashboards.sellers');
    Route::get('/dashboards/sellers/export', [SellersDashboardController::class, 'export'])
        ->middleware('feature:sellers')->name('dashboards.sellers.export');
});
