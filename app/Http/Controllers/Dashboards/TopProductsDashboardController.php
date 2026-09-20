<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Страница «Топ товаров»: топ и анти-топ по выручке за месяц с MoM. */
class TopProductsDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts): View
    {
        $period = $this->requestedMonth($request);
        $limit = (int) config('analytics.display.table_limit');

        $top = $tables->topProducts($period, Direction::Desc, $limit);
        // Период анти-топа — тот же, что определился для топа (один latestPeriod).
        $antiTop = $top->period === null
            ? $top
            : $tables->topProducts(Period::fromKey($top->period), Direction::Asc, $limit);

        return view('dashboards.top-products', ['top' => $top, 'antiTop' => $antiTop, 'topChart' => $charts->topRevenue($top)]);
    }
}
