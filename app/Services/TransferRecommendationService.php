<?php

namespace App\Services;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\Period;
use App\Core\Transfers\StockSurplusDonor;
use App\Core\Transfers\TransferPlanner;
use App\Core\Transfers\TransferPosition;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;

/**
 * Рекомендации перемещений за месяц по уже посчитанной метрике days_of_stock:
 * читает позиции только тех товаров, у которых есть дефицитная пара, и
 * отдаёт их планировщику. Остаток и скорость — из value_meta снэпшота.
 * К адаптеру не обращается. Пороги — из analytics.transfers.
 *
 * ЭВРИСТИКА ДЛЯ ДЕМО (см. TransferDonorReason::StockSurplus и Known issues
 * в docs/roadmap.md, «склад без продаж не считается донором»): для тех же
 * товаров (у которых есть дефицитная пара) дополнительно читается
 * DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY — склады без единой
 * продажи, но с остатком выше analytics.transfers.stock_surplus_min_stock,
 * передаются планировщику как доноры «по остатку» (reason=stock_surplus).
 * Не финальное продуктовое решение — уточняется при реальном клиенте.
 */
final class TransferRecommendationService
{
    public function __construct(
        private readonly MetricsComparisonRepository $repository,
        private readonly MetricsSnapshotRepository $snapshots,
    ) {}

    public function forPeriod(Period $period): TransferRecommendationResult
    {
        $config = (array) config('analytics.transfers');
        $planner = new TransferPlanner(
            (float) $config['deficit_days'],
            (float) $config['target_days'],
            (float) $config['keep_days'],
            (float) $config['surplus_days'],
            (int) $config['min_quantity'],
        );

        $read = 0;
        $skipped = 0;
        $productIds = [];
        $positions = (function () use ($period, $config, &$read, &$skipped, &$productIds) {
            $rows = $this->repository->rowsOfProductsWithValueAtMost(
                DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period, (float) $config['deficit_days'],
            );
            foreach ($rows as $row) {
                [$productId, $warehouseId] = ProductWarehouseKey::parse($row->entityId);
                $productIds[$productId] = true;

                $stock = $row->valueMeta['stock_qty'] ?? null;
                $rate = $row->valueMeta['daily_rate'] ?? null;
                if (! is_numeric($stock) || ! is_numeric($rate)) {
                    $skipped++;

                    continue;
                }

                $read++;

                yield new TransferPosition($productId, $warehouseId, (float) $stock, (float) $rate);
            }
        })();

        // Материализуется здесь: нужен полный набор productId до чтения
        // stock_no_demand (генератор $positions выше ленивый).
        $positions = iterator_to_array($positions, false);

        $threshold = (float) $config['stock_surplus_min_stock'];
        $stockSurplusDonors = [];
        foreach ($this->snapshots->findByPeriodKeys(
            DaysOfStockCalculator::ENTITY_TYPE, DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY, [$period->key()],
        ) as $record) {
            [$productId, $warehouseId] = ProductWarehouseKey::parse($record->entityId);
            if (! isset($productIds[$productId]) || $record->value <= $threshold) {
                continue;
            }

            $stockSurplusDonors[] = new StockSurplusDonor($productId, $warehouseId, $record->value - $threshold);
        }

        $plan = $planner->plan($positions, $stockSurplusDonors);

        return new TransferRecommendationResult($plan, $read, $skipped);
    }
}
