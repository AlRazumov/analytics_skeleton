<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Widgets\MonthNavigationProvider;
use App\Core\Widgets\ProductCardProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Карточка товара `/dashboards/products/{id}`: все метрики товара за месяц
 * (`?period=`, по умолчанию — последний с выручкой) и динамика по месяцам.
 * Товара нет в справочнике — 404.
 */
class ProductCardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, string $product, ProductCardProvider $cards, MonthNavigationProvider $months): View
    {
        $card = $cards->card($product, $this->requestedMonth($request), (int) config('analytics.display.product_history_months'));
        if ($card === null) {
            abort(404);
        }

        return view('dashboards.product', [
            'card' => $card,
            'revenueChart' => $cards->revenueChart($card),
            'deadStockDays' => (int) config('analytics.display.dead_stock_display_days'),
            'stockoutRiskDays' => (int) config('analytics.display.stockout_risk_days'),
            'monthNav' => $months->for($card->period, [
                ['product', 'revenue'],
                ['product', 'turnover'],
                [DeadStockCalculator::ENTITY_TYPE, DeadStockCalculator::METRIC_KEY],
                [ProductWarehouseKey::ENTITY_TYPE, DaysOfStockCalculator::METRIC_KEY],
            ]),
        ]);
    }
}
