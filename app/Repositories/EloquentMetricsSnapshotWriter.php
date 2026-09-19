<?php

namespace App\Repositories;

use App\Core\Domain\Period;
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

        $rows = array_map(static function (MetricsSnapshotRecord $record) use ($now): array {
            $period = Period::fromKey($record->period);

            return [
                'entity_type' => $record->entityType,
                'entity_id' => $record->entityId,
                'metric_key' => $record->metricKey,
                'value' => $record->value,
                'value_meta' => $record->valueMeta === [] ? null : json_encode($record->valueMeta),
                'period_type' => $period->granularity->value,
                'period_start' => $period->start->format('Y-m-d'),
                'period_end' => $period->end->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            MetricsSnapshot::query()->insert($chunk);
        }
    }
}
