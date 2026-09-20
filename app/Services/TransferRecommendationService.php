<?php

namespace App\Services;

use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use App\Core\Domain\Period;
use App\Core\Transfers\TransferPlanner;
use App\Core\Transfers\TransferPosition;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;

/**
 * Рекомендации перемещений за месяц по уже посчитанной метрике days_of_stock:
 * читает позиции только тех товаров, у которых есть дефицитная пара, и
 * отдаёт их планировщику. Остаток и скорость — из value_meta снэпшота.
 * К адаптеру не обращается. Пороги — из analytics.transfers.
 *
 * ОГРАНИЧЕНИЕ v1: склад с остатком, но без продаж (для пары нет строки
 * days_of_stock — метрика не пишется без спроса) донором не считается.
 */
final class TransferRecommendationService
{
    public function __construct(private readonly MetricsComparisonRepository $repository) {}

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
        $positions = (function () use ($period, $config, &$read, &$skipped) {
            $rows = $this->repository->rowsOfProductsWithValueAtMost(
                DaysOfStockCalculator::METRIC_KEY, DaysOfStockCalculator::ENTITY_TYPE, $period, (float) $config['deficit_days'],
            );
            foreach ($rows as $row) {
                $stock = $row->valueMeta['stock_qty'] ?? null;
                $rate = $row->valueMeta['daily_rate'] ?? null;
                if (! is_numeric($stock) || ! is_numeric($rate)) {
                    $skipped++;

                    continue;
                }

                [$productId, $warehouseId] = ProductWarehouseKey::parse($row->entityId);
                $read++;

                yield new TransferPosition($productId, $warehouseId, (float) $stock, (float) $rate);
            }
        })();

        $plan = $planner->plan($positions);

        return new TransferRecommendationResult($plan, $read, $skipped);
    }
}
