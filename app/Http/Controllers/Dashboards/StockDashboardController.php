<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Widgets\Contracts\ProductCategoryResolver;
use App\Core\Widgets\MonthNavigationProvider;
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
 * `?category=` — только товары категории (таблицы и графики).
 * `exportDeadStock`/`exportStockoutRisk` — все строки таблицы в CSV
 * (с теми же порогами показа и категорией).
 */
class StockDashboardController extends Controller
{
    use ResolvesCategory;
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts, ProductCategoryResolver $categories, MonthNavigationProvider $months): View
    {
        $period = $this->requestedMonth($request);
        $options = $this->categoryOptions($categories);
        $category = $this->requestedCategory($request, $options);
        $limit = (int) config('analytics.display.table_limit');

        $deadDays = (int) config('analytics.display.dead_stock_display_days');
        $riskDays = (int) config('analytics.display.stockout_risk_days');

        $deadStock = config('analytics.features.dead_stock') ? $tables->deadStock($period, $deadDays, $limit, $category) : null;
        $stockoutRisk = config('analytics.features.stockout_risk') ? $tables->stockoutRisk($period, $riskDays, $limit, $category) : null;

        return view('dashboards.stock', [
            'deadStock' => $deadStock,
            'deadStockChart' => config('analytics.features.dead_stock')
                ? $charts->deadStockAge($period, $deadDays, array_values(array_map('intval', (array) config('analytics.display.dead_stock_age_bounds'))), $category)
                : null,
            'deadStockDays' => $deadDays,
            'stockoutRisk' => $stockoutRisk,
            'daysOfStockChart' => config('analytics.features.stockout_risk')
                ? $charts->daysOfStock($period, array_values(array_map('intval', (array) config('analytics.display.days_of_stock_bounds'))), $category)
                : null,
            'stockoutRiskDays' => $riskDays,
            'category' => $category,
            'categoryOptions' => $options,
            'monthNav' => $months->for($deadStock->period ?? $stockoutRisk?->period, [
                [DeadStockCalculator::ENTITY_TYPE, DeadStockCalculator::METRIC_KEY],
                [DaysOfStockCalculator::ENTITY_TYPE, DaysOfStockCalculator::METRIC_KEY],
            ]),
        ]);
    }

    public function exportDeadStock(Request $request, ProductTablesProvider $tables, ProductCategoryResolver $categories): Response
    {
        $category = $this->requestedCategory($request, $this->categoryOptions($categories));
        $data = $tables->deadStock($this->requestedMonth($request), (int) config('analytics.display.dead_stock_display_days'), null, $category);

        return $this->csvResponse(TableCsv::deadStock($data), 'dead-stock', $data->period, [$this->categorySuffix($category)]);
    }

    public function exportStockoutRisk(Request $request, ProductTablesProvider $tables, ProductCategoryResolver $categories): Response
    {
        $category = $this->requestedCategory($request, $this->categoryOptions($categories));
        $data = $tables->stockoutRisk($this->requestedMonth($request), (int) config('analytics.display.stockout_risk_days'), null, $category);

        return $this->csvResponse(TableCsv::stockoutRisk($data), 'stockout-risk', $data->period, [$this->categorySuffix($category)]);
    }
}
