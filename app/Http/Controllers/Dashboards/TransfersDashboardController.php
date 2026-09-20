<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Services\TransferTableProvider;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Страница «Перемещения»: рекомендации по складам за месяц (метрика days_of_stock). */
class TransfersDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    public function __invoke(Request $request, TransferTableProvider $transfers): View
    {
        return view('dashboards.transfers', [
            'transfers' => $transfers->forPeriod($this->requestedMonth($request), (int) config('analytics.display.table_limit')),
            'thresholds' => (array) config('analytics.transfers'),
        ]);
    }
}
