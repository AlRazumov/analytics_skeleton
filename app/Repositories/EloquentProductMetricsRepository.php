<?php

namespace App\Repositories;

use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\ProductMetricsRepository;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;
use Illuminate\Database\Eloquent\Builder;

/**
 * ProductMetricsRepository поверх metrics_snapshots. Строки товара — по
 * unique-индексу (entity_type, entity_id, metric_key, …); строки пар — по
 * индексу (metric_key, period_type, period_start, …) с фильтром по товарной
 * части ключа.
 */
final class EloquentProductMetricsRepository implements ProductMetricsRepository
{
    public function forProduct(string $metricKey, string $productId, array $periodKeys): array
    {
        if ($periodKeys === []) {
            return [];
        }

        $query = MetricsSnapshot::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $productId)
            ->where('metric_key', $metricKey)
            ->where(function (Builder $q) use ($periodKeys) {
                foreach ($periodKeys as $key) {
                    $period = Period::fromKey($key);
                    $q->orWhere(fn (Builder $p) => $p->where('period_type', $period->granularity->value)
                        ->where('period_start', $period->start->format('Y-m-d')));
                }
            });

        $records = [];
        foreach ($query->get() as $row) {
            $record = self::record($row);
            $records[$record->period] = $record;
        }

        return $records;
    }

    public function forProductWarehouses(string $metricKey, string $productId, string $periodKey): array
    {
        $period = Period::fromKey($periodKey);

        return array_values(MetricsSnapshot::query()
            ->where('metric_key', $metricKey)
            ->where('period_type', $period->granularity->value)
            ->where('period_start', $period->start->format('Y-m-d'))
            ->where('entity_type', ProductWarehouseKey::ENTITY_TYPE)
            ->whereRaw("split_part(entity_id, ':', 1) = ?", [$productId])
            ->orderByRaw('entity_id COLLATE "C"')
            ->get()
            ->map(self::record(...))
            ->all());
    }

    private static function record(MetricsSnapshot $row): MetricsSnapshotRecord
    {
        return new MetricsSnapshotRecord(
            entityType: $row->entity_type,
            entityId: $row->entity_id,
            metricKey: $row->metric_key,
            value: (float) $row->value,
            period: Period::containing(PeriodGranularity::from($row->period_type), $row->period_start)->key(),
            valueMeta: $row->value_meta ?? [],
        );
    }
}
