<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use App\Support\TableCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Оборачиваемость» (штуки): самые низкие и высокие значения и
 * распределение по корзинам. `export` — все товары по возрастанию в CSV.
 */
class TurnoverDashboardController extends Controller
{
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

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
            'distribution' => $period === null ? null : $charts->turnoverDistribution($period, array_values(array_map('floatval', (array) config('analytics.display.turnover_bounds')))),
        ]);
    }

    public function export(Request $request, ProductTablesProvider $tables): Response
    {
        $data = $tables->turnover($this->requestedMonth($request), Direction::Asc, null);

        return $this->csvResponse(TableCsv::turnover($data), 'turnover', $data->period);
    }
}
