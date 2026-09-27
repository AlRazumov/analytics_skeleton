<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Services\TransferTableProvider;
use App\Support\TableCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Перемещения»: рекомендации по складам за месяц (метрика
 * days_of_stock). `export` — все рекомендации в CSV.
 */
class TransfersDashboardController extends Controller
{
    use ResolvesMonthPeriod;
    use RespondsWithCsv;

    public function __invoke(Request $request, TransferTableProvider $transfers): View
    {
        return view('dashboards.transfers', [
            'transfers' => $transfers->forPeriod($this->requestedMonth($request), (int) config('analytics.display.table_limit')),
            'thresholds' => (array) config('analytics.transfers'),
        ]);
    }

    public function export(Request $request, TransferTableProvider $transfers): Response
    {
        $data = $transfers->forPeriod($this->requestedMonth($request), null);

        return $this->csvResponse(TableCsv::transfers($data), 'transfers', $data->period);
    }
}
