<?php

namespace App\Core\Widgets;

use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\EntityNameResolver;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\DTO\MetricComparisonRow;
use App\Core\Widgets\DTO\TopNData;
use App\Core\Widgets\DTO\TopNRow;

/**
 * Обобщённый топ-N по (entity_type, metric) поверх снэпшотов. Топ — запрос
 * ORDER BY value DESC LIMIT N, отдельно не хранится. Для типов с «сущностью
 * без значения» ($unassigned: entity_type => [id, подпись]) такая строка
 * выделяется отдельно и в рейтинг не входит; режим покрытия читается из её
 * value_meta[$coverageKey] строки метрики $coverageMetric.
 */
final readonly class TopNProvider
{
    /** Потолок выборки для типов с небольшим справочником (продавцы). */
    private const int ALL = 1000;

    /**
     * @param  array<string, array{id: string, label: string}>  $unassigned
     */
    public function __construct(
        private MetricsComparisonRepository $repository,
        private EntityNameResolver $names,
        private array $unassigned = [],
        private string $coverageMetric = 'sales_count',
        private string $coverageKey = 'seller_on_deal',
    ) {}

    /**
     * @param  list<string>  $columns  дополнительные метрики (колонки таблицы)
     */
    public function topN(string $entityType, string $metric, ?Period $period, int $limit, array $columns = []): ?TopNData
    {
        $period ??= $this->repository->latestPeriod($metric, PeriodGranularity::Month);
        if ($period === null) {
            return null;
        }

        $unassignedId = $this->unassigned[$entityType]['id'] ?? null;
        $fetch = $unassignedId === null ? $limit : self::ALL;
        $rows = $this->repository->top($metric, $entityType, $period, $fetch, RankBy::Value, Direction::Desc);
        if ($rows === []) {
            return null;
        }

        $unassignedRow = null;
        if ($unassignedId !== null) {
            foreach ($rows as $i => $row) {
                if ($row->entityId === $unassignedId) {
                    $unassignedRow = $row;
                    unset($rows[$i]);
                }
            }
            $rows = array_values($rows);
        }
        $total = $unassignedId === null ? $this->repository->count($metric, $entityType, $period) : count($rows);
        $rows = array_slice($rows, 0, $limit);

        $extras = [];
        foreach (array_diff($columns, [$metric]) as $column) {
            foreach ($this->repository->top($column, $entityType, $period, self::ALL) as $r) {
                $extras[$column][$r->entityId] = $r->value;
            }
        }

        $ids = array_map(static fn (MetricComparisonRow $r) => $r->entityId, $rows);
        $names = $this->names->names($entityType, $ids);
        $build = fn (MetricComparisonRow $r, string $name) => new TopNRow(
            $r->entityId,
            $name,
            $r->value,
            array_map(static fn (array $byId) => $byId[$r->entityId] ?? 0.0, $extras),
        );

        [$coverage, $percent] = $unassignedId === null ? [null, null] : $this->coverage($entityType, $period, $unassignedId);

        return new TopNData(
            $entityType,
            $metric,
            $period->key(),
            array_map(fn (MetricComparisonRow $r) => $build($r, $names[$r->entityId] ?? $r->entityId), $rows),
            $total,
            array_keys($extras),
            $unassignedRow === null ? null : $build($unassignedRow, $this->unassigned[$entityType]['label']),
            $coverage,
            $percent,
        );
    }

    /**
     * @return array{?string, ?float} режим покрытия и доля продаж с известной сущностью
     */
    private function coverage(string $entityType, Period $period, string $unassignedId): array
    {
        $mode = null;
        $assigned = 0.0;
        $none = 0.0;
        foreach ($this->repository->top($this->coverageMetric, $entityType, $period, self::ALL) as $r) {
            if ($r->entityId === $unassignedId) {
                $none = $r->value;
                $mode = $r->valueMeta[$this->coverageKey] ?? null;
            } else {
                $assigned += $r->value;
            }
        }
        $total = $assigned + $none;

        return [$mode, $total > 0 ? $assigned / $total * 100 : null];
    }
}
