<?php

namespace App\Repositories;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\DTO\MatrixCellData;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use App\Models\MetricsSnapshot;
use DateTimeImmutable;
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

        return $this->scoped($entityType, $metricKey, $periodKeys)
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

    public function sumsByPeriod(string $entityType, string $metricKey, array $periodKeys): array
    {
        if ($periodKeys === []) {
            return [];
        }

        $sums = [];
        foreach ($this->scoped($entityType, $metricKey, $periodKeys)
            ->toBase()
            ->selectRaw('period_type, period_start, SUM(value) AS total')
            ->groupBy('period_type', 'period_start')
            ->get() as $row) {
            $sums[self::key((string) $row->period_type, (string) $row->period_start)] = (float) $row->total;
        }

        return $sums;
    }

    public function topBySum(string $entityType, string $metricKey, array $periodKeys, int $limit): array
    {
        if ($periodKeys === [] || $limit < 1) {
            return ['rows' => [], 'total' => 0];
        }

        $ids = $this->scoped($entityType, $metricKey, $periodKeys)
            ->toBase()
            ->select('entity_id')
            ->groupBy('entity_id')
            ->orderByRaw('SUM(value) DESC')
            ->orderByRaw('entity_id COLLATE "C"')
            ->limit($limit)
            ->pluck('entity_id')
            ->map(static fn ($id) => (string) $id)
            ->all();
        $total = (int) $this->scoped($entityType, $metricKey, $periodKeys)->toBase()->distinct()->count('entity_id');

        $byEntity = array_fill_keys($ids, []);
        foreach ($this->scoped($entityType, $metricKey, $periodKeys)
            ->toBase()
            ->whereIn('entity_id', $ids)
            ->select('entity_id', 'period_type', 'period_start', 'value')
            ->get() as $row) {
            $byEntity[(string) $row->entity_id][self::key((string) $row->period_type, (string) $row->period_start)] = (float) $row->value;
        }

        $rows = [];
        foreach ($byEntity as $id => $byPeriod) {
            $rows[] = ['entityId' => (string) $id, 'byPeriod' => $byPeriod];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function cellsByMeta(string $entityType, string $metricKey, string $periodKey, string $rowMetaKey, string $colMetaKey): array
    {
        $cells = [];
        foreach ($this->scoped($entityType, $metricKey, [$periodKey])
            ->toBase()
            ->selectRaw('COALESCE(value_meta->>?, ?) AS row_key, COALESCE(value_meta->>?, ?) AS col_key, COUNT(*) AS items, SUM(value) AS total', [$rowMetaKey, '?', $colMetaKey, '?'])
            ->groupBy('row_key', 'col_key')
            ->orderBy('row_key')
            ->orderBy('col_key')
            ->get() as $row) {
            $cells[] = new MatrixCellData((string) $row->row_key, (string) $row->col_key, (int) $row->items, (float) $row->total);
        }

        return $cells;
    }

    public function latestPeriodFor(string $entityType, string $metricKey): ?string
    {
        $row = MetricsSnapshot::query()
            ->where('entity_type', $entityType)
            ->where('metric_key', $metricKey)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : self::keyOf($row);
    }

    /**
     * Строки (entity_type, metric_key) за периоды $periodKeys.
     *
     * @param  string[]  $periodKeys
     * @return Builder<MetricsSnapshot>
     */
    private function scoped(string $entityType, string $metricKey, array $periodKeys): Builder
    {
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
            });
    }

    private static function key(string $periodType, string $periodStart): string
    {
        return Period::containing(PeriodGranularity::from($periodType), new DateTimeImmutable($periodStart))->key();
    }

    private static function keyOf(MetricsSnapshot $row): string
    {
        return Period::containing(
            PeriodGranularity::from($row->period_type),
            $row->period_start,
        )->key();
    }
}
