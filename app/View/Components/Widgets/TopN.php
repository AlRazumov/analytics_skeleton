<?php

namespace App\View\Components\Widgets;

use App\Core\Domain\Period;
use App\Services\TopNTableProvider;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-widgets.top-n entity-type="seller" metric="sales_count" :limit="3" :period="$period" />
 *
 * Обобщённый топ-N: таблица + bar chart. Какие метрики и колонки
 * показываются — см. TopNTableProvider. Блок не рендерится, если данных
 * нет или источник не отдаёт продавцов (режим none).
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
        $tables = app(TopNTableProvider::class);
        $data = $tables->table($this->entityType, $this->metric, $this->period, $this->limit);
        if ($data?->coverage === 'none') {
            $data = null;
        }

        return view('components.widgets.top-n', [
            'data' => $data,
            'chart' => $data === null ? null : $tables->chart($data),
            'limit' => $this->limit,
        ]);
    }
}
