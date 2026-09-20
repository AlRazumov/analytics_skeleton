<?php

namespace App\Services;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\Contracts\WarehouseNameResolver;
use App\Core\Widgets\DTO\TransferRow;
use App\Core\Widgets\DTO\TransferTableData;

/**
 * Данные страницы перемещений: рекомендации сервиса + названия из
 * справочников (по одному запросу на товары и на склады), лимит строк.
 * Лежит в app/Services (а не в core/Widgets), потому что использует
 * прикладной сервис; адаптера не касается. Период — явный или последний
 * у метрики days_of_stock.
 */
final readonly class TransferTableProvider
{
    public function __construct(
        private TransferRecommendationService $service,
        private MetricsComparisonRepository $repository,
        private ProductNameResolver $products,
        private WarehouseNameResolver $warehouses,
    ) {}

    public function forPeriod(?Period $period, int $limit): TransferTableData
    {
        $period ??= $this->repository->latestPeriod(DaysOfStockCalculator::METRIC_KEY, PeriodGranularity::Month);
        if ($period === null) {
            return new TransferTableData(null, false, [], 0, 0, 0);
        }

        if ($this->repository->count(DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period) === 0) {
            return new TransferTableData($period->key(), false, [], 0, 0, 0);
        }

        $plan = $this->service->forPeriod($period)->plan;
        $shown = array_slice($plan->recommendations, 0, max(0, $limit));

        $products = $this->products->names(array_values(array_unique(array_map(static fn ($r) => $r->productId, $shown))));
        $warehouses = $this->warehouses->names(array_values(array_unique(array_merge(
            array_map(static fn ($r) => $r->fromWarehouseId, $shown),
            array_map(static fn ($r) => $r->toWarehouseId, $shown),
        ))));

        return new TransferTableData(
            $period->key(),
            true,
            array_map(static fn ($r) => new TransferRow(
                $r->productId,
                $products[$r->productId] ?? $r->productId,
                $r->fromWarehouseId,
                $warehouses[$r->fromWarehouseId] ?? $r->fromWarehouseId,
                $r->toWarehouseId,
                $warehouses[$r->toWarehouseId] ?? $r->toWarehouseId,
                $r->quantity,
                $r->fromCoverageBefore,
                $r->fromCoverageAfter,
                $r->toCoverageBefore,
                $r->toCoverageAfter,
                $r->toDailyRate,
            ), $shown),
            count($plan->recommendations),
            $plan->deficitPairs,
            $plan->unmatchedDeficits,
        );
    }
}
