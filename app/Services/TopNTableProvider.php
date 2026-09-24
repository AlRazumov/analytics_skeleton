<?php

namespace App\Services;

use App\Core\Domain\Period;
use App\Core\Widgets\DTO\LineChartData;
use App\Core\Widgets\DTO\Series;
use App\Core\Widgets\DTO\SeriesPoint;
use App\Core\Widgets\DTO\TopNData;
use App\Core\Widgets\TopNProvider;

/**
 * Топ-N поверх TopNProvider с учётом конфига: показываются только метрики,
 * включённые в config('analytics.enabled_metrics.<тип>') (типы без записи не
 * фильтруются). Колонки по умолчанию — config('analytics.top_n.<тип>.columns').
 * Общий источник для виджета <x-widgets.top-n>, страницы продавцов и её CSV.
 */
final readonly class TopNTableProvider
{
    public function __construct(private TopNProvider $provider) {}

    /** @return list<string>|null включённые метрики типа; null — фильтра нет */
    public function enabledMetrics(string $entityType): ?array
    {
        $enabled = config("analytics.enabled_metrics.{$entityType}");

        return $enabled === null ? null : array_values((array) $enabled);
    }

    public function isEnabled(string $entityType, string $metric): bool
    {
        $enabled = $this->enabledMetrics($entityType);

        return $enabled === null || in_array($metric, $enabled, true);
    }

    /**
     * null — метрика выключена или данных за период нет. Режим покрытия
     * 'none' возвращается как есть: что показать, решает вызывающий.
     *
     * @param  list<string>|null  $columns  null — колонки из config top_n
     */
    public function table(string $entityType, string $metric, ?Period $period, int $limit, ?array $columns = null): ?TopNData
    {
        if (! $this->isEnabled($entityType, $metric)) {
            return null;
        }

        $columns ??= (array) config("analytics.top_n.{$entityType}.columns", []);
        $columns = array_values(array_filter($columns, fn (string $key) => $this->isEnabled($entityType, $key)));

        return $this->provider->topN($entityType, $metric, $period, $limit, $columns);
    }

    public function chart(TopNData $data): LineChartData
    {
        return new LineChartData(
            $data->metric,
            [new Series($data->metric, array_map(fn ($r) => new SeriesPoint($r->name, $r->value), $data->rows))],
        );
    }
}
