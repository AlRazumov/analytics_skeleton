<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Единственная точка чтения агрегированных метрик для слоя widgets.
 * Реализация (Eloquent/`metrics_snapshots`) живёт вне core — core знает
 * только об этом контракте.
 */
interface MetricsSnapshotRepository
{
    /**
     * @param  string[]  $periodKeys  ключи `metrics_snapshots.period`
     * @return MetricsSnapshotRecord[]
     */
    public function findByPeriodKeys(string $entityType, string $metricKey, array $periodKeys): array;
}
