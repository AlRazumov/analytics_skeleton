<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\View\View;

/**
 * Демо-страница дашборда "Обзор продаж" на StandaloneLayout — та же
 * связка WidgetDataProvider + widgets, что и в DemoWidgetsController,
 * только на общем каркасе (шапка/навигация/футер).
 */
class OverviewDashboardController extends Controller
{
    public function __invoke(WidgetDataProvider $widgets): View
    {
        $period = new Period(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-06-30'),
            PeriodGranularity::Month,
        );

        return view('dashboards.overview', [
            'kpiCard' => $widgets->kpiCard('product', 'revenue', $period, unit: '₽'),
            'lineChart' => $widgets->lineChart('product', 'revenue', $period),
            'barChart' => $widgets->lineChartYoY('product', 'revenue', $period),
            'table' => $widgets->table('product', 'revenue', $period),
        ]);
    }
}
