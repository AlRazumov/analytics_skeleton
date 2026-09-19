<?php

namespace App\Repositories;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;
use Illuminate\Database\Eloquent\Builder;

/**
 * Реализация MetricsSnapshotRepository поверх Eloquent-модели
 * MetricsSnapshot. Живёт вне core — core знает только о контракте.
 * Ключи периода в контракте — канонические (Period::key()); в БД они
 * хранятся как (period_type, period_start).
 */
final class EloquentMetricsSnapshotRepository implements MetricsSnapshotRepository
{
    public function findByPeriodKeys(string $entityType, string $metricKey, array $periodKeys): array
    {
        if ($periodKeys === []) {
            return [];
        }

        $startsByType = [];
        foreach ($periodKeys as $key) {
            $period = Period::fromKey($key);
            $startsByType[$period->granularity->value][] = $period->start->format('Y-m-d');
        }

        return MetricsSnapshot::query()
            ->where('entity_type', $entityType)
            ->where('metric_key', $metricKey)
            ->where(function (Builder $query) use ($startsByType) {
                foreach ($startsByType as $type => $starts) {
                    $query->orWhere(fn (Builder $q) => $q->where('period_type', $type)->whereIn('period_start', $starts));
                }
            })
            ->orderBy('period_start')
            ->get()
            ->map(static fn (MetricsSnapshot $row) => new MetricsSnapshotRecord(
                entityType: $row->entity_type,
                entityId: $row->entity_id,
                metricKey: $row->metric_key,
                value: (float) $row->value,
                period: self::keyOf($row),
                valueMeta: $row->value_meta ?? [],
            ))
            ->all();
    }

    public function latestPeriodFor(string $entityType, string $metricKey): ?string
    {
        $row = MetricsSnapshot::query()
            ->where('entity_type', $entityType)
            ->where('metric_key', $metricKey)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : self::keyOf($row);
    }

    private static function keyOf(MetricsSnapshot $row): string
    {
        return Period::containing(
            PeriodGranularity::from($row->period_type),
            $row->period_start,
        )->key();
    }
}
