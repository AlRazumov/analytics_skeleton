<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
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
 * Страница «Топ товаров»: топ и анти-топ по выручке за месяц. База
 * сравнения — `?base=previous|year_ago` (по умолчанию previous; иное
 * значение — 404). Если в выбранной базе нет данных, страница отдаёт
 * пояснение вместо таблиц. `?category=` — только товары категории.
 * `export` — все товары с выручкой за месяц (по убыванию) в CSV, с той же
 * базой сравнения и категорией.
 */
class TopProductsDashboardController extends Controller
{
    use ResolvesCategory;
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts, ProductCategoryResolver $categories, MonthNavigationProvider $months): View
    {
        $period = $this->requestedMonth($request);
        $base = $this->requestedBase($request);
        $options = $this->categoryOptions($categories);
        $category = $this->requestedCategory($request, $options);
        $limit = (int) config('analytics.display.table_limit');

        $top = $tables->topProducts($period, Direction::Desc, $limit, $base, $category);
        // Период анти-топа — тот же, что определился для топа (один latestPeriod).
        $resolved = $top->period === null ? null : Period::fromKey($top->period);
        $antiTop = $resolved === null ? $top : $tables->topProducts($resolved, Direction::Asc, $limit, $base, $category);

        // Год назад — только если у какого-то товара (категории) есть выручка в том же месяце прошлого года.
        $yearAgoAvailable = $resolved !== null && $tables->hasRevenueBase($resolved, ComparisonBase::YearAgo, $category);

        return view('dashboards.top-products', [
            'top' => $top,
            'antiTop' => $antiTop,
            'topChart' => $charts->topRevenue($top),
            'base' => $base,
            'yearAgoAvailable' => $yearAgoAvailable,
            'baseMissing' => $resolved !== null && $base === ComparisonBase::YearAgo && ! $yearAgoAvailable,
            'periodKey' => $top->period,
            'monthNav' => $months->for($top->period, [['product', 'revenue']]),
            'category' => $category,
            'categoryOptions' => $options,
        ]);
    }

    public function export(Request $request, ProductTablesProvider $tables, ProductCategoryResolver $categories): Response
    {
        $base = $this->requestedBase($request);
        $category = $this->requestedCategory($request, $this->categoryOptions($categories));
        $data = $tables->topProducts($this->requestedMonth($request), Direction::Desc, null, $base, $category);

        return $this->csvResponse(
            TableCsv::topProducts($data, $base), 'top-products', $data->period,
            [$base === ComparisonBase::YearAgo ? 'year-ago' : null, $this->categorySuffix($category)],
        );
    }

    private function requestedBase(Request $request): ComparisonBase
    {
        if (! $request->query->has('base')) {
            return ComparisonBase::Previous;
        }

        $value = $request->query('base');

        return (is_string($value) ? ComparisonBase::tryFrom($value) : null) ?? abort(404);
    }
}
