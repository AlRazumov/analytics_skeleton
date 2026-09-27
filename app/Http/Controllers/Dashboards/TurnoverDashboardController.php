<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\ProductCategoryResolver;
use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use App\Support\TableCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Оборачиваемость» (штуки): самые низкие и высокие значения и
 * распределение по корзинам. `?category=` — только товары категории.
 * `export` — все товары по возрастанию в CSV (с той же категорией).
 */
class TurnoverDashboardController extends Controller
{
    use ResolvesCategory;
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts, ProductCategoryResolver $categories): View
    {
        $period = $this->requestedMonth($request);
        $options = $this->categoryOptions($categories);
        $category = $this->requestedCategory($request, $options);
        $limit = (int) config('analytics.display.table_limit');

        $lowest = $tables->turnover($period, Direction::Asc, $limit, $category);
        // Период остальных виджетов — тот же, что определился для первой таблицы.
        $period = $lowest->period === null ? null : Period::fromKey($lowest->period);

        return view('dashboards.turnover', [
            'lowest' => $lowest,
            'highest' => $period === null ? $lowest : $tables->turnover($period, Direction::Desc, $limit, $category),
            'distribution' => $period === null ? null : $charts->turnoverDistribution($period, array_values(array_map('floatval', (array) config('analytics.display.turnover_bounds'))), $category),
            'category' => $category,
            'categoryOptions' => $options,
        ]);
    }

    public function export(Request $request, ProductTablesProvider $tables, ProductCategoryResolver $categories): Response
    {
        $category = $this->requestedCategory($request, $this->categoryOptions($categories));
        $data = $tables->turnover($this->requestedMonth($request), Direction::Asc, null, $category);

        return $this->csvResponse(TableCsv::turnover($data), 'turnover', $data->period, [$this->categorySuffix($category)]);
    }
}
