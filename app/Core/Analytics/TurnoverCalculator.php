<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Оборачиваемость запасов по (товар, месяц): units sold / средний
 * остаток за месяц.
 *
 * ДОПУЩЕНИЕ (методология не следует однозначно из имеющегося кода —
 * зафиксировано в отчёте stage-04): DataSourceAdapter не предоставляет
 * абсолютный остаток на складе (fetchStock() появился позже, расчёт на
 * него пока не переведён), только поток движений fetchStockMovements().
 * Поэтому "остаток" здесь
 * — накопленный сальдо движений (сумма движений со знаком; пары
 * transfer_in/transfer_out не меняют сальдо товара в целом), начиная с 0 в
 * начале запрошенного периода. Это ОТНОСИТЕЛЬНЫЙ остаток внутри окна
 * расчёта, а не абсолютный физический остаток на конец месяца — при
 * отсутствии ненулевого остатка на начало периода в реальном источнике
 * данных все месяцы, кроме первых, будут занижать реальную
 * оборачиваемость. Способ расчёта: для каждого месяца period
 * берётся average(opening, closing) как средний остаток; продажи месяца
 * = сумма −quantity движений типа Sale; turnover = unitsSold / avgStock
 * (в разах за месяц). Если avgStock == 0, turnover не определён — в
 * таком случае снэпшот не пишется (нет базы для деления, а не
 * "оборачиваемость 0").
 */
final class TurnoverCalculator
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'turnover';

    /**
     * @param  iterable<StockMovement>  $movements
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $movements, DateRange $period): array
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

        $records = [];
        foreach ($netByProductAndMonth as $productId => $byMonth) {
            $balance = 0.0;
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
                    valueMeta: [],
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
