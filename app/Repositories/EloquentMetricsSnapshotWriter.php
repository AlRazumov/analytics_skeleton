<?php

namespace App\Repositories;

use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;

/**
 * Реализация MetricsSnapshotWriter поверх Eloquent-модели
 * MetricsSnapshot. Живёт вне core — core знает только о контракте.
 */
final class EloquentMetricsSnapshotWriter implements MetricsSnapshotWriter
{
    private const int CHUNK_SIZE = 500;

    public function write(array $records): void
    {
        $now = now();

        $rows = array_map(static fn (MetricsSnapshotRecord $record): array => [
            'entity_type' => $record->entityType,
            'entity_id' => $record->entityId,
            'metric_key' => $record->metricKey,
            'value' => $record->value,
            'value_meta' => $record->valueMeta === [] ? null : json_encode($record->valueMeta),
            'period' => $record->period,
            'created_at' => $now,
            'updated_at' => $now,
        ], $records);

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            MetricsSnapshot::query()->insert($chunk);
        }
    }
}
