<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\DTO\TopNData;
use App\Http\Controllers\Controller;
use App\Services\TopNTableProvider;
use App\Support\TopNCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Страница «Продавцы»: все продавцы за месяц, ранжированные по метрике
 * `?metric=` (любая из analytics.enabled_metrics.seller; по умолчанию
 * sales_count, иначе первая включённая; иное значение — 404), колонки —
 * все включённые метрики. `export` отдаёт ту же таблицу в CSV.
 */
class SellersDashboardController extends Controller
{
    use ResolvesMonthPeriod;

    private const string ENTITY_TYPE = 'seller';

    private const string DEFAULT_METRIC = 'sales_count';

    /** Продавцов немного — на странице показываются все. */
    private const int LIMIT = 1000;

    public function __invoke(Request $request, TopNTableProvider $tables): View
    {
        $metric = $this->requestedMetric($request, $tables);
        $data = $metric === null ? null : $this->data($request, $tables, $metric);

        return view('dashboards.sellers', [
            'metric' => $metric,
            'metrics' => $tables->enabledMetrics(self::ENTITY_TYPE) ?? [],
            'data' => $data,
            'chart' => $data === null ? null : $tables->chart($data),
        ]);
    }

    public function export(Request $request, TopNTableProvider $tables): Response
    {
        $metric = $this->requestedMetric($request, $tables);
        $data = $metric === null ? null : $this->data($request, $tables, $metric);
        if ($data === null) {
            abort(404);
        }

        $month = substr($data->period, strpos($data->period, ':') + 1);

        return response(TopNCsv::build($data), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"sellers-{$metric}-{$month}.csv\"",
        ]);
    }

    /**
     * null — данных нет. В режиме покрытия none строки продавцов не пишутся
     * вовсе, так что это тоже «нет данных»; проверка coverage — страховка.
     */
    private function data(Request $request, TopNTableProvider $tables, string $metric): ?TopNData
    {
        $data = $tables->table(
            self::ENTITY_TYPE,
            $metric,
            $this->requestedMonth($request),
            self::LIMIT,
            $tables->enabledMetrics(self::ENTITY_TYPE) ?? [$metric],
        );

        return $data?->coverage === 'none' ? null : $data;
    }

    /** null — ни одна метрика продавцов не включена. */
    private function requestedMetric(Request $request, TopNTableProvider $tables): ?string
    {
        $enabled = $tables->enabledMetrics(self::ENTITY_TYPE) ?? [self::DEFAULT_METRIC];

        if (! $request->query->has('metric')) {
            return in_array(self::DEFAULT_METRIC, $enabled, true) ? self::DEFAULT_METRIC : ($enabled[0] ?? null);
        }

        $metric = $request->query('metric');

        return is_string($metric) && in_array($metric, $enabled, true) ? $metric : abort(404);
    }
}
