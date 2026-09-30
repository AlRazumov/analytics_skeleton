<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Domain\PeriodRange;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\MonthNavigationProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use App\Services\TransferTableProvider;
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

    public function __invoke(Request $request, WidgetDataProvider $widgets, MetricsComparisonRepository $repository, MonthNavigationProvider $months, ProductTablesProvider $tables, TransferTableProvider $transfers): View
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
            'monthNav' => $months->for($end->key(), [['product', 'revenue']]),
            'kpiCard' => $widgets->kpiCard('product', 'revenue', $period, unit: '₽'),
            'lineChart' => $widgets->lineChart('product', 'revenue', $period),
            'barChart' => $widgets->lineChartYoY('product', 'revenue', $period),
            'attention' => $this->attention($end, $tables, $transfers),
            'period' => $end,
            'table' => $widgets->topTable('product', 'revenue', $period, (int) config('analytics.display.table_limit'), productNames: true),
        ]);
    }

    /**
     * Плитки «Требует внимания»: сколько неликвидов, пар с риском дефицита и
     * рекомендаций перемещений за месяц; каждая — под флагом своей страницы,
     * со ссылкой на неё.
     *
     * @return list<array{label: string, count: int, hint: string, url: string}>
     */
    private function attention(Period $month, ProductTablesProvider $tables, TransferTableProvider $transfers): array
    {
        $tiles = [];
        $query = ['period' => $month->key()];

        if (config('analytics.features.dead_stock')) {
            $days = (int) config('analytics.display.dead_stock_display_days');
            $tiles[] = [
                'label' => 'Неликвиды',
                'count' => $tables->deadStock($month, $days, 1)->total,
                'hint' => "Товары без продаж {$days} дней и более",
                'url' => route('dashboards.stock', $query),
            ];
        }
        if (config('analytics.features.stockout_risk')) {
            $days = (int) config('analytics.display.stockout_risk_days');
            $tiles[] = [
                'label' => 'Риск дефицита',
                'count' => $tables->stockoutRisk($month, $days, 1)->total,
                'hint' => "Пар товар × склад, где остатка хватит не более чем на {$days} дн.",
                'url' => route('dashboards.stock', $query),
            ];
        }
        if (config('analytics.features.transfers')) {
            $tiles[] = [
                'label' => 'Перемещения',
                'count' => $transfers->forPeriod($month, 0)->total,
                'hint' => 'Рекомендаций довезти товар между складами',
                'url' => route('dashboards.transfers', $query),
            ];
        }

        return $tiles;
    }
}
