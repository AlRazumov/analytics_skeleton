<?php

use App\Http\Controllers\Dashboards\AbcXyzDashboardController;
use App\Http\Controllers\Dashboards\OverviewDashboardController;
use App\Http\Controllers\DemoWidgetsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Standalone-часть (StandaloneLayout и демо виджетов на данных
// metrics_snapshots) — только для вошедших пользователей. Будущая
// iframe-группа сюда не входит: её авторизация — задача этапа Bitrix24Adapter.
Route::middleware('auth')->group(function () {
    Route::get('/demo/widgets', DemoWidgetsController::class);

    Route::get('/dashboards/overview', OverviewDashboardController::class)->name('dashboards.overview');
    Route::get('/dashboards/abc-xyz', AbcXyzDashboardController::class)->name('dashboards.abc-xyz');
});
