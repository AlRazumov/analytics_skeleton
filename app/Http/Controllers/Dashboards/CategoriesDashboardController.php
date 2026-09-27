<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Period;
use App\Core\Widgets\CategoryProvider;
use App\Http\Controllers\Controller;
use App\Support\TableCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Категории»: выручка по категориям за месяц с долей и
 * сравнением с базой (`?base=previous|year_ago`, как на «Топ товаров»;
 * иное значение — 404) и помесячная динамика за 12 месяцев. `export` —
 * таблица категорий в CSV.
 */
class CategoriesDashboardController extends Controller
{
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, CategoryProvider $categories): View
    {
        $base = $this->requestedBase($request);
        $table = $categories->table($this->requestedMonth($request), $base);
        $resolved = $table->period === null ? null : Period::fromKey($table->period);
        $yearAgoAvailable = $resolved !== null && $categories->hasBase($resolved, ComparisonBase::YearAgo);

        return view('dashboards.categories', [
            'table' => $table,
            'revenueChart' => $categories->revenueChart($table),
            'monthlyChart' => $resolved === null ? null : $categories->monthlyChart($resolved),
            'base' => $base,
            'yearAgoAvailable' => $yearAgoAvailable,
            'baseMissing' => $resolved !== null && $base === ComparisonBase::YearAgo && ! $yearAgoAvailable,
            'periodKey' => $table->period,
        ]);
    }

    public function export(Request $request, CategoryProvider $categories): Response
    {
        $base = $this->requestedBase($request);
        $table = $categories->table($this->requestedMonth($request), $base);

        return $this->csvResponse(
            TableCsv::categories($table, $base), 'categories', $table->period,
            [$base === ComparisonBase::YearAgo ? 'year-ago' : null],
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
