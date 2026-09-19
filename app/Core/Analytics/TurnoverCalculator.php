<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Оборачиваемость запасов по (товар, месяц): units sold / средний
 * остаток за месяц (штуки, «раз за месяц»).
 *
 * Остаток. avgStock = (opening + closing) / 2. opening первого месяца
 * диапазона — РЕАЛЬНЫЙ остаток на (начало диапазона − 1 день), суммарно
 * по всем складам, его передаёт вызывающий в $openingStock
 * (MetricsCalculationService берёт его одним вызовом fetchStock()).
 * opening следующих месяцев = closing предыдущего; closing = opening +
 * сальдо движений месяца (все типы; пары transfer_in/transfer_out
 * перемещают остаток между складами и на уровне товара в сумме дают 0 —
 * в коде они пропускаются, так что результат не зависит от того,
 * попали ли обе ножки перемещения в выборку). Продажи месяца = сумма
 * −quantity движений типа Sale.
 *
 * $openingStock по умолчанию [] означает нулевой стартовый остаток. Это
 * корректно ТОЛЬКО когда диапазон начинается там, где остаток нулевой
 * (например, с начала истории источника); иначе значения искажены —
 * см. docs/reports/stage-08-period-comparison.md, раздел 7.
 *
 * Строки пишутся для товаров с движениями в диапазоне ∪ товаров с
 * $openingStock > 0 (товар с остатком и без движений даёт turnover 0
 * в каждом месяце диапазона). avgStock <= 0 → turnover не определён
 * (нет базы для деления), снэпшот не пишется. Неполные первый и
 * последний месяцы диапазона не нормируются.
 *
 * value_meta: units_sold, avg_stock, opening_stock, closing_stock.
 */
final class TurnoverCalculator
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'turnover';

    /**
     * @param  iterable<StockMovement>  $movements
     * @param  array<string, float>  $openingStock  productId → остаток на (начало диапазона − 1 день)
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $movements, DateRange $period, array $openingStock = []): array
    {
        $months = $this->monthKeys($period);
        if ($months === []) {
            return [];
        }

        // Сумма ± движения по товару за каждый месяц (quantity со знаком;
        // transfer_in/out не меняют сальдо товара в целом — перемещение между
        // своими же складами).
        $netByProductAndMonth = [];
        $outByProductAndMonth = [];

        foreach ($movements as $movement) {
            $month = $movement->date->format('Y-m');

            $delta = match ($movement->type) {
                StockMovementType::TransferIn, StockMovementType::TransferOut => 0.0,
                default => $movement->quantity,
            };

            $netByProductAndMonth[$movement->productId][$month] = ($netByProductAndMonth[$movement->productId][$month] ?? 0.0) + $delta;

            if ($movement->type === StockMovementType::Sale) {
                $outByProductAndMonth[$movement->productId][$month] = ($outByProductAndMonth[$movement->productId][$month] ?? 0.0) - $movement->quantity;
            }
        }

        $productIds = array_keys($netByProductAndMonth);
        foreach ($openingStock as $productId => $quantity) {
            if ($quantity > 0.0 && ! isset($netByProductAndMonth[$productId])) {
                $productIds[] = (string) $productId;
            }
        }

        $records = [];
        foreach ($productIds as $productId) {
            $byMonth = $netByProductAndMonth[$productId] ?? [];
            $balance = (float) ($openingStock[$productId] ?? 0.0);
            foreach ($months as $month) {
                $opening = $balance;
                $balance += $byMonth[$month] ?? 0.0;
                $closing = $balance;

                $avgStock = ($opening + $closing) / 2;
                if ($avgStock <= 0.0) {
                    continue;
                }

                $unitsSold = $outByProductAndMonth[$productId][$month] ?? 0.0;
                $turnover = $unitsSold / $avgStock;

                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: $productId,
                    metricKey: self::METRIC_KEY,
                    value: $turnover,
                    period: 'month:'.$month,
                    valueMeta: [
                        'units_sold' => $unitsSold,
                        'avg_stock' => $avgStock,
                        'opening_stock' => $opening,
                        'closing_stock' => $closing,
                    ],
                );
            }
        }

        return $records;
    }

    /**
     * @return string[]
     */
    private function monthKeys(DateRange $period): array
    {
        $cursor = new DateTimeImmutable($period->start->format('Y-m-01'));
        $last = new DateTimeImmutable($period->end->format('Y-m-01'));

        $months = [];
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }
}
