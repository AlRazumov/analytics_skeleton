<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Неликвиды: days_since_last_sale по (товар, месяц), значение на asOf
 * = min(конец месяца, конец запрошенного диапазона).
 *
 * Значение — дней от последней продажи (sale, любой склад) до asOf.
 * Снэпшот пишется для ВСЕХ товаров с суммарным остатком > 0 на asOf;
 * «неликвид или нет» — запрос value >= порога (threshold_days лежит в
 * value_meta только для справки, флага в БД нет). Момента "сейчас" метрика
 * не знает: результат зависит только от адаптера и диапазона.
 *
 * Lookback: движения читаются с (начало диапазона − dead_stock_days),
 * поэтому расчёт за один месяц видит продажи за предыдущие ~порог дней и
 * даёт те же значения (в части «value >= порога»), что расчёт за более
 * широкий диапазон. Снэпшоты пишутся только за месяцы запрошенного
 * диапазона. Если в расширенном окне до asOf продаж нет, value = дней от
 * НАЧАЛА расширенного окна до asOf — это НИЖНЯЯ ГРАНИЦА (реальная
 * последняя продажа старше), value_meta.no_sales_in_lookback = true;
 * такое значение всегда >= порога.
 *
 * Остаток: fetchStock(начало расширенного окна − 1 день) плюс движения
 * окна (все типы), суммарно по складам. Движения читаются одним потоком;
 * в памяти — по товару и месяцу (сумма движений и дата последней
 * продажи), а не вся история. Порядок движений не важен.
 *
 * Требует capabilities StockMovements и StockSnapshots (проверяет
 * MetricsCalculationService).
 */
final class DeadStockCalculator
{
    public const ENTITY_TYPE = 'product';

    public const METRIC_KEY = 'days_since_last_sale';

    private const EPSILON = 1e-9;

    public function __construct(private readonly int $thresholdDays = 90) {}

    /**
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array
    {
        $rangeStart = new DateTimeImmutable($period->start->format('Y-m-d'));
        $rangeEnd = new DateTimeImmutable($period->end->format('Y-m-d'));
        $lookbackStart = $rangeStart->modify('-'.$this->thresholdDays.' days');

        $stock = [];
        foreach ($adapter->fetchStock($lookbackStart->modify('-1 day')) as $balance) {
            $stock[$balance->productId] = ($stock[$balance->productId] ?? 0.0) + $balance->quantity;
        }

        $deltaByMonth = [];
        $lastSaleByMonth = [];
        foreach ($adapter->fetchStockMovements(new DateRange($lookbackStart, $rangeEnd)) as $movement) {
            $month = $movement->date->format('Y-m');
            $deltaByMonth[$movement->productId][$month] = ($deltaByMonth[$movement->productId][$month] ?? 0.0) + $movement->quantity;

            if ($movement->type === StockMovementType::Sale) {
                $day = $movement->date->format('Y-m-d');
                if ($day > ($lastSaleByMonth[$movement->productId][$month] ?? '')) {
                    $lastSaleByMonth[$movement->productId][$month] = $day;
                }
            }
        }

        $productIds = array_unique([...array_keys($stock), ...array_keys($deltaByMonth)]);
        sort($productIds);

        $records = [];
        $lastSale = [];
        foreach (Months::in($lookbackStart, $rangeEnd) as $month) {
            $asOf = min($month->end, $rangeEnd);
            $monthKey = $month->start->format('Y-m');

            foreach ($productIds as $productId) {
                $stock[$productId] = ($stock[$productId] ?? 0.0) + ($deltaByMonth[$productId][$monthKey] ?? 0.0);
                if (isset($lastSaleByMonth[$productId][$monthKey])) {
                    $lastSale[$productId] = $lastSaleByMonth[$productId][$monthKey];
                }

                // Месяцы lookback только накапливают остаток и последнюю продажу.
                if ($month->end < $rangeStart || $stock[$productId] <= self::EPSILON) {
                    continue;
                }

                $meta = ['stock_qty' => $stock[$productId], 'threshold_days' => $this->thresholdDays];
                if (isset($lastSale[$productId])) {
                    $since = new DateTimeImmutable($lastSale[$productId]);
                } else {
                    $since = $lookbackStart;
                    $meta['no_sales_in_lookback'] = true;
                }

                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: $productId,
                    metricKey: self::METRIC_KEY,
                    value: (float) $since->diff($asOf)->days,
                    period: 'month:'.$monthKey,
                    valueMeta: $meta,
                );
            }
        }

        return $records;
    }
}
