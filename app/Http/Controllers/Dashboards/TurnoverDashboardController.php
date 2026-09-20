<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Страница «Оборачиваемость» (штуки): самые низкие и высокие значения и распределение по корзинам. */
class TurnoverDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts): View
    {
        $period = $this->requestedMonth($request);
        $limit = (int) config('analytics.display.table_limit');

        $lowest = $tables->turnover($period, Direction::Asc, $limit);
        // Период остальных виджетов — тот же, что определился для первой таблицы.
        $period = $lowest->period === null ? null : Period::fromKey($lowest->period);

        return view('dashboards.turnover', [
            'lowest' => $lowest,
            'highest' => $period === null ? $lowest : $tables->turnover($period, Direction::Desc, $limit),
            'distribution' => $period === null ? null : $charts->turnoverDistribution($period, array_map('floatval', config('analytics.display.turnover_bounds'))),
        ]);
    }
}
