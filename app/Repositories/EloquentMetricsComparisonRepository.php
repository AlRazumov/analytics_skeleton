<?php

namespace App\Repositories;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\DTO\MetricComparisonRow;
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
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit должен быть в диапазоне 1..'.self::MAX_LIMIT.", получено {$limit}.");
        }
        if ($by !== RankBy::Value && $base === null) {
            throw new InvalidArgumentException("Ранжирование по {$by->value} требует базы сравнения (ComparisonBase).");
        }

        $query = $this->query($metricKey, $entityType, $period, $base);

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

    private function query(string $metricKey, string $entityType, Period $period, ?ComparisonBase $base): Builder
    {
        $query = DB::table('metrics_snapshots as cur')
            ->where('cur.metric_key', $metricKey)
            ->where('cur.entity_type', $entityType)
            ->where('cur.period_type', $period->granularity->value)
            ->where('cur.period_start', $period->start->format('Y-m-d'))
            ->select('cur.entity_id', 'cur.value');

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
        );
    }
}
