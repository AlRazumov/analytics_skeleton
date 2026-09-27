<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use App\Support\TableCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Остатки»: неликвиды и риск дефицита. Каждый виджет включается
 * своим флагом (analytics.features.*); метрики от флагов не зависят.
 * `exportDeadStock`/`exportStockoutRisk` — все строки таблицы в CSV
 * (с теми же порогами показа).
 */
class StockDashboardController extends Controller
{
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts): View
    {
        $period = $this->requestedMonth($request);
        $limit = (int) config('analytics.display.table_limit');

        $deadDays = (int) config('analytics.display.dead_stock_display_days');
        $riskDays = (int) config('analytics.display.stockout_risk_days');

        return view('dashboards.stock', [
            'deadStock' => config('analytics.features.dead_stock') ? $tables->deadStock($period, $deadDays, $limit) : null,
            'deadStockChart' => config('analytics.features.dead_stock')
                ? $charts->deadStockAge($period, $deadDays, array_values(array_map('intval', (array) config('analytics.display.dead_stock_age_bounds'))))
                : null,
            'deadStockDays' => $deadDays,
            'stockoutRisk' => config('analytics.features.stockout_risk') ? $tables->stockoutRisk($period, $riskDays, $limit) : null,
            'daysOfStockChart' => config('analytics.features.stockout_risk')
                ? $charts->daysOfStock($period, array_values(array_map('intval', (array) config('analytics.display.days_of_stock_bounds'))))
                : null,
            'stockoutRiskDays' => $riskDays,
        ]);
    }

    public function exportDeadStock(Request $request, ProductTablesProvider $tables): Response
    {
        $data = $tables->deadStock($this->requestedMonth($request), (int) config('analytics.display.dead_stock_display_days'), null);

        return $this->csvResponse(TableCsv::deadStock($data), 'dead-stock', $data->period);
    }

    public function exportStockoutRisk(Request $request, ProductTablesProvider $tables): Response
    {
        $data = $tables->stockoutRisk($this->requestedMonth($request), (int) config('analytics.display.stockout_risk_days'), null);

        return $this->csvResponse(TableCsv::stockoutRisk($data), 'stockout-risk', $data->period);
    }
}
