<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Страница «Остатки»: неликвиды и риск дефицита. Каждый виджет включается
 * своим флагом (analytics.features.*); метрики от флагов не зависят.
 */
class StockDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, ProductTablesProvider $tables): View
    {
        $period = $this->requestedMonth($request);
        $limit = (int) config('analytics.display.table_limit');

        $deadDays = (int) config('analytics.display.dead_stock_display_days');
        $riskDays = (int) config('analytics.display.stockout_risk_days');

        return view('dashboards.stock', [
            'deadStock' => config('analytics.features.dead_stock') ? $tables->deadStock($period, $deadDays, $limit) : null,
            'deadStockDays' => $deadDays,
            'stockoutRisk' => config('analytics.features.stockout_risk') ? $tables->stockoutRisk($period, $riskDays, $limit) : null,
            'stockoutRiskDays' => $riskDays,
        ]);
    }
}
