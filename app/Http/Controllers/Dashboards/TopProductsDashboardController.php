<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Period;
use App\Core\Widgets\ProductChartsProvider;
use App\Core\Widgets\ProductTablesProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Страница «Топ товаров»: топ и анти-топ по выручке за месяц. База
 * сравнения — `?base=previous|year_ago` (по умолчанию previous; иное
 * значение — 404). Если в выбранной базе нет данных, страница отдаёт
 * пояснение вместо таблиц.
 */
class TopProductsDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, ProductTablesProvider $tables, ProductChartsProvider $charts): View
    {
        $period = $this->requestedMonth($request);
        $base = $this->requestedBase($request);
        $limit = (int) config('analytics.display.table_limit');

        $top = $tables->topProducts($period, Direction::Desc, $limit, $base);
        // Период анти-топа — тот же, что определился для топа (один latestPeriod).
        $resolved = $top->period === null ? null : Period::fromKey($top->period);
        $antiTop = $resolved === null ? $top : $tables->topProducts($resolved, Direction::Asc, $limit, $base);

        // Год назад — только если у какого-то товара есть выручка в том же месяце прошлого года.
        $yearAgoAvailable = $resolved !== null && $tables->hasRevenueBase($resolved, ComparisonBase::YearAgo);

        return view('dashboards.top-products', [
            'top' => $top,
            'antiTop' => $antiTop,
            'topChart' => $charts->topRevenue($top),
            'base' => $base,
            'yearAgoAvailable' => $yearAgoAvailable,
            'baseMissing' => $resolved !== null && $base === ComparisonBase::YearAgo && ! $yearAgoAvailable,
            'periodKey' => $top->period,
        ]);
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
