<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\PeriodRange;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Дашборд "Обзор продаж" на StandaloneLayout. Окно — OVERVIEW_MONTHS
 * месяцев, заканчивающихся на `?period=month:YYYY-MM` либо на последнем
 * периоде выручки из хранилища (без текущего времени).
 */
class OverviewDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    private const int OVERVIEW_MONTHS = 6;

    public function __invoke(Request $request, WidgetDataProvider $widgets, MetricsComparisonRepository $repository): View
    {
        $end = $this->requestedMonth($request) ?? $repository->latestPeriod('revenue', PeriodGranularity::Month);

        if ($end === null) {
            return view('dashboards.overview', ['empty' => true]);
        }

        $period = new PeriodRange(
            $end->start->modify('-'.(self::OVERVIEW_MONTHS - 1).' months'),
            $end->end,
            PeriodGranularity::Month,
        );

        return view('dashboards.overview', [
            'empty' => false,
            'periodKey' => $end->key(),
            'kpiCard' => $widgets->kpiCard('product', 'revenue', $period, unit: '₽'),
            'lineChart' => $widgets->lineChart('product', 'revenue', $period),
            'barChart' => $widgets->lineChartYoY('product', 'revenue', $period),
            'period' => $end,
            'table' => $widgets->table('product', 'revenue', $period, productNames: true),
        ]);
    }
}
