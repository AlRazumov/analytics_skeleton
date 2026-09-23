<?php

namespace App\View\Components\Widgets;

use App\Core\Domain\Period;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;
use App\Core\Widgets\TopNProvider;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-widgets.top-n entity-type="seller" metric="sales_count" :limit="3" :period="$period" />
 *
 * Обобщённый топ-N: таблица + bar chart. Показываются только метрики,
 * включённые в config('analytics.enabled_metrics.<тип>') (типы без записи
 * не фильтруются). Колонки таблицы — config('analytics.top_n.<тип>.columns').
 * Блок не рендерится, если данных нет или источник не отдаёт продавцов (режим none).
 */
class TopN extends Component
{
    public function __construct(
        public string $entityType,
        public string $metric,
        public int $limit = 3,
        public ?Period $period = null,
    ) {}

    public function render(): View
    {
        $enabled = config("analytics.enabled_metrics.{$this->entityType}");
        $allowed = fn (string $key): bool => $enabled === null || in_array($key, (array) $enabled, true);

        $data = null;
        $chart = null;
        if ($allowed($this->metric)) {
            $columns = array_values(array_filter(
                (array) config("analytics.top_n.{$this->entityType}.columns", []), $allowed,
            ));
            $data = app(TopNProvider::class)->topN($this->entityType, $this->metric, $this->period, $this->limit, $columns);
            if ($data !== null && $data->coverage !== 'none') {
                $chart = new LineChartData(
                    $this->metric,
                    [new Series($this->metric, array_map(fn ($r) => new SeriesPoint($r->name, $r->value), $data->rows))],
                );
            } else {
                $data = null;
            }
        }

        return view('components.widgets.top-n', ['data' => $data, 'chart' => $chart, 'limit' => $this->limit]);
    }
}
