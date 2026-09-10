<?php

namespace App\Repositories;

use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;

/**
 * Реализация MetricsSnapshotRepository поверх Eloquent-модели
 * MetricsSnapshot. Живёт вне core — core знает только о контракте.
 */
final class EloquentMetricsSnapshotRepository implements MetricsSnapshotRepository
{
    public function findByPeriodKeys(string $entityType, string $metricKey, array $periodKeys): array
    {
        return MetricsSnapshot::query()
            ->where('entity_type', $entityType)
            ->where('metric_key', $metricKey)
            ->whereIn('period', $periodKeys)
            ->orderBy('period')
            ->get()
            ->map(static fn (MetricsSnapshot $row) => new MetricsSnapshotRecord(
                entityType: $row->entity_type,
                entityId: $row->entity_id,
                metricKey: $row->metric_key,
                value: (float) $row->value,
                period: $row->period,
                valueMeta: $row->value_meta ?? [],
            ))
            ->all();
    }
}
