<?php

namespace App\Repositories;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\DTO\MetricComparisonRow;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Реализация MetricsComparisonRepository запросами к metrics_snapshots.
 * База присоединяется LEFT JOIN-ом по (entity_type, entity_id, metric_key,
 * period_type, period_start) — этот набор уникален (unique-индекс), поэтому
 * строк не размножает. Живёт вне core.
 */
final class EloquentMetricsComparisonRepository implements MetricsComparisonRepository
{
    private const int MAX_LIMIT = 1000;

    private const string DELTA_ABS_SQL = '(cur.value - base.value)';

    // База 0 → NULL (деление на ноль), иначе процент от |базы|.
    private const string DELTA_PCT_SQL = 'CASE WHEN base.value = 0 THEN NULL ELSE (cur.value - base.value) / ABS(base.value) * 100 END';

    public function compare(string $metricKey, string $entityType, Period $period, ComparisonBase $base): iterable
    {
        $query = $this->query($metricKey, $entityType, $period, $base)
            ->orderByRaw('cur.entity_id COLLATE "C" asc');

        foreach ($query->cursor() as $row) {
            yield $this->toRow($entityType, $row);
        }
    }

    public function top(
        string $metricKey,
        string $entityType,
        Period $period,
        int $limit,
        RankBy $by = RankBy::Value,
        Direction $dir = Direction::Desc,
        ?ComparisonBase $base = null,
        ?float $minValue = null,
        ?float $maxValue = null,
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit должен быть в диапазоне 1..'.self::MAX_LIMIT.", получено {$limit}.");
        }
        if ($by !== RankBy::Value && $base === null) {
            throw new InvalidArgumentException("Ранжирование по {$by->value} требует базы сравнения (ComparisonBase).");
        }

        $query = $this->query($metricKey, $entityType, $period, $base);
        $this->applyValueRange($query, $minValue, $maxValue);

        // Направление берётся только из enum — в SQL подставляется константа.
        $direction = $dir === Direction::Asc ? 'asc' : 'desc';
        match ($by) {
            RankBy::Value => $query->orderByRaw("cur.value {$direction}"),
            RankBy::DeltaAbs => $query->whereNotNull('base.value')->orderByRaw(self::DELTA_ABS_SQL." {$direction}"),
            RankBy::DeltaPct => $query->whereNotNull('base.value')->where('base.value', '<>', 0)
                ->orderByRaw(self::DELTA_PCT_SQL." {$direction}"),
        };

        return $query->orderByRaw('cur.entity_id COLLATE "C" asc')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->toRow($entityType, $row))
            ->all();
    }

    public function count(
        string $metricKey,
        string $entityType,
        Period $period,
        ?float $minValue = null,
        ?float $maxValue = null,
    ): int {
        $query = $this->query($metricKey, $entityType, $period, null);
        $this->applyValueRange($query, $minValue, $maxValue);

        return $query->count();
    }

    public function latestPeriod(string $metricKey, PeriodGranularity $granularity): ?Period
    {
        $start = DB::table('metrics_snapshots')
            ->where('metric_key', $metricKey)
            ->where('period_type', $granularity->value)
            ->max('period_start');

        return $start === null
            ? null
            : Period::containing($granularity, new DateTimeImmutable(substr((string) $start, 0, 10)));
    }

    public function bucketCounts(string $metricKey, string $entityType, Period $period, array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $selects = [];
        $bindings = [];
        foreach ($ranges as $i => $range) {
            $conditions = [];
            if ($range->min !== null) {
                $conditions[] = 'cur.value '.($range->minInclusive ? '>=' : '>').' ?';
                $bindings[] = $range->min;
            }
            if ($range->max !== null) {
                $conditions[] = 'cur.value '.($range->maxInclusive ? '<=' : '<').' ?';
                $bindings[] = $range->max;
            }
            $selects[] = 'COUNT(*) FILTER (WHERE '.($conditions === [] ? 'TRUE' : implode(' AND ', $conditions)).") AS b{$i}";
        }

        $row = $this->current($metricKey, $entityType, $period)
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        return array_map(static fn (int $i) => (int) $row->{"b{$i}"}, array_keys($ranges));
    }

    public function rowsOfProductsWithValueAtMost(string $metricKey, string $entityType, Period $period, float $maxValue): iterable
    {
        $deficitProducts = $this->current($metricKey, $entityType, $period)
            ->where('cur.value', '<=', $maxValue)
            ->selectRaw("split_part(cur.entity_id, ':', 1)");

        $query = $this->current($metricKey, $entityType, $period)
            ->select('cur.entity_id', 'cur.value', 'cur.value_meta')
            ->whereRaw("split_part(cur.entity_id, ':', 1) IN ({$deficitProducts->toSql()})", $deficitProducts->getBindings())
            ->orderByRaw('cur.entity_id COLLATE "C" asc');

        foreach ($query->cursor() as $row) {
            yield MetricComparisonRow::of(
                $entityType,
                $row->entity_id,
                (float) $row->value,
                null,
                $row->value_meta === null ? [] : (json_decode($row->value_meta, true) ?? []),
            );
        }
    }

    private function applyValueRange(Builder $query, ?float $minValue, ?float $maxValue): void
    {
        if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
            throw new InvalidArgumentException("minValue ({$minValue}) не может быть больше maxValue ({$maxValue}).");
        }
        if ($minValue !== null) {
            $query->where('cur.value', '>=', $minValue);
        }
        if ($maxValue !== null) {
            $query->where('cur.value', '<=', $maxValue);
        }
    }

    private function current(string $metricKey, string $entityType, Period $period): Builder
    {
        return DB::table('metrics_snapshots as cur')
            ->where('cur.metric_key', $metricKey)
            ->where('cur.entity_type', $entityType)
            ->where('cur.period_type', $period->granularity->value)
            ->where('cur.period_start', $period->start->format('Y-m-d'));
    }

    private function query(string $metricKey, string $entityType, Period $period, ?ComparisonBase $base): Builder
    {
        $query = $this->current($metricKey, $entityType, $period)
            ->select('cur.entity_id', 'cur.value', 'cur.value_meta');

        if ($base === null) {
            return $query->addSelect(DB::raw('NULL as base_value'));
        }

        $baseStart = ($base === ComparisonBase::Previous ? $period->previous() : $period->yearAgo())
            ->start->format('Y-m-d');

        return $query
            ->leftJoin('metrics_snapshots as base', function (JoinClause $join) use ($baseStart) {
                $join->on('base.entity_type', '=', 'cur.entity_type')
                    ->on('base.entity_id', '=', 'cur.entity_id')
                    ->on('base.metric_key', '=', 'cur.metric_key')
                    ->on('base.period_type', '=', 'cur.period_type')
                    ->where('base.period_start', '=', $baseStart);
            })
            ->addSelect('base.value as base_value');
    }

    private function toRow(string $entityType, object $row): MetricComparisonRow
    {
        return MetricComparisonRow::of(
            $entityType,
            $row->entity_id,
            (float) $row->value,
            $row->base_value === null ? null : (float) $row->base_value,
            $row->value_meta === null ? [] : (json_decode($row->value_meta, true) ?? []),
        );
    }
}
