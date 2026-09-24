<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Дни до обнуления по (товар × склад, месяц), значение на asOf =
 * min(конец месяца, конец запрошенного диапазона).
 *
 * entity_type='product_warehouse', entity_id = ProductWarehouseKey
 * ("<productId>:<warehouseId>"), metric_key='days_of_stock'.
 *
 *   спрос/день = сумма продаж (sale) за «дни в наличии» окна W дней,
 *                заканчивающегося на asOf / число этих дней
 *   значение   = остаток на asOf / спрос/день
 *
 * «День в наличии» — день, в который остаток НА НАЧАЛО дня был > 0.
 * Числитель и знаменатель берутся по одним и тем же дням: дни с
 * нулевым остатком не входят в знаменатель, иначе провалы по остатку
 * занижали бы скорость продаж и завышали «дни до обнуления», а
 * продажи такого дня (например, дня прихода: партия пришла утром,
 * остаток на начало дня 0) не входят в числитель, иначе скорость
 * завышалась бы.
 * Остаток по дням окна: fetchStock(начало окна − 1 день) + движения
 * окна (все типы, только эта пара). Продажи вне окна и другие типы
 * спросом не считаются.
 *
 * Снэпшот НЕ пишется (а не 0 и не бесконечность), если спроса в дни в наличии нет или
 * дней с остатком в окне меньше min_in_stock_days; счётчики пропусков
 * доступны в $lastSkipped. Остаток на asOf = 0 при наличии спроса даёт 0.
 * value_meta: stock_qty, daily_rate, in_stock_days, window_days.
 *
 * Пары без единой продажи (спроса в окне нет вообще, не только в дни в
 * наличии) дополнительно попадают в NO_DEMAND_STOCK_METRIC_KEY — см. её
 * докблок; это отдельная, узкая эвристика для донора-по-остатку, а не
 * замена days_of_stock.
 *
 * ОГРАНИЧЕНИЕ: сезонность не учитывается — окно короткое, скорость
 * считается плоской.
 *
 * Читает окно отдельным вызовом fetchStock/fetchStockMovements на
 * каждый месяц (потоково; в памяти — движения одного окна).
 * Требует capabilities StockMovements и StockSnapshots.
 */
final class DaysOfStockCalculator
{
    public const ENTITY_TYPE = ProductWarehouseKey::ENTITY_TYPE;

    public const METRIC_KEY = 'days_of_stock';

    /**
     * ЭВРИСТИКА ДЛЯ ДЕМО (не финальное продуктовое решение, см. Known
     * issues в docs/roadmap.md, «склад без продаж не считается донором»):
     * остаток на пару товар×склад, для которой days_of_stock НЕ считается
     * из-за отсутствия спроса (нет ни одной продажи в окне) — единственный
     * источник знания об остатке таких пар, нужен TransferRecommendationService
     * для донора «по остатку», а не по обороту. Пишется, только когда
     * итоговый остаток на конец месяца положителен — в том числе для пар,
     * начавших окно с нуля и получивших товар внутри окна.
     */
    public const NO_DEMAND_STOCK_METRIC_KEY = 'stock_no_demand';

    private const EPSILON = 1e-9;

    /** @var array{no_demand: int, too_few_in_stock_days: int} пропуски последнего calculate() */
    public array $lastSkipped = ['no_demand' => 0, 'too_few_in_stock_days' => 0];

    public function __construct(
        private readonly int $windowDays = 28,
        private readonly int $minInStockDays = 7,
    ) {}

    /**
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array
    {
        $rangeStart = new DateTimeImmutable($period->start->format('Y-m-d'));
        $rangeEnd = new DateTimeImmutable($period->end->format('Y-m-d'));
        $this->lastSkipped = ['no_demand' => 0, 'too_few_in_stock_days' => 0];

        $records = [];
        foreach (Months::in($rangeStart, $rangeEnd) as $month) {
            $asOf = min($month->end, $rangeEnd);
            $windowStart = $asOf->modify('-'.($this->windowDays - 1).' days');
            $window = new DateRange($windowStart, $asOf);

            $opening = [];
            foreach ($adapter->fetchStock($windowStart->modify('-1 day')) as $balance) {
                $opening[ProductWarehouseKey::make($balance->productId, $balance->warehouseId)] = $balance->quantity;
            }

            $saleByDay = [];
            $deltaByDay = [];
            foreach ($adapter->fetchStockMovements($window) as $movement) {
                $key = ProductWarehouseKey::make($movement->productId, $movement->warehouseId);
                $dayIndex = (int) $windowStart->diff(new DateTimeImmutable($movement->date->format('Y-m-d')))->format('%r%a');
                if ($dayIndex < 0 || $dayIndex >= $this->windowDays) {
                    continue;
                }

                $deltaByDay[$key][$dayIndex] = ($deltaByDay[$key][$dayIndex] ?? 0.0) + $movement->quantity;
                if ($movement->type === StockMovementType::Sale) {
                    $saleByDay[$key][$dayIndex] = ($saleByDay[$key][$dayIndex] ?? 0.0) - $movement->quantity;
                }
            }

            // Пары без продаж — и с остатком на начало окна, и получившие
            // товар внутри окна (приход/перемещение на склад с нуля).
            foreach (array_keys($opening + $deltaByDay) as $key) {
                if (isset($saleByDay[$key])) {
                    continue;
                }

                $quantity = $opening[$key] ?? 0.0;
                $finalStock = $quantity;
                for ($day = 0; $day < $this->windowDays; $day++) {
                    $finalStock += $deltaByDay[$key][$day] ?? 0.0;
                }
                $finalStock = max(0.0, $finalStock);

                if ($quantity > self::EPSILON || $finalStock > self::EPSILON) {
                    $this->lastSkipped['no_demand']++;

                    if ($finalStock > self::EPSILON) {
                        $records[] = new MetricsSnapshotRecord(
                            entityType: self::ENTITY_TYPE,
                            entityId: $key,
                            metricKey: self::NO_DEMAND_STOCK_METRIC_KEY,
                            value: $finalStock,
                            period: 'month:'.$month->start->format('Y-m'),
                            valueMeta: ['stock_qty' => $finalStock],
                        );
                    }
                }
            }

            ksort($saleByDay);
            foreach ($saleByDay as $key => $salesByDay) {
                $stock = $opening[$key] ?? 0.0;
                $inStockDays = 0;
                $soldInStockDays = 0.0;
                for ($day = 0; $day < $this->windowDays; $day++) {
                    if ($stock > self::EPSILON) {
                        $inStockDays++;
                        $soldInStockDays += $salesByDay[$day] ?? 0.0;
                    }
                    $stock += $deltaByDay[$key][$day] ?? 0.0;
                }

                if ($inStockDays < $this->minInStockDays) {
                    $this->lastSkipped['too_few_in_stock_days']++;

                    continue;
                }

                if ($soldInStockDays <= self::EPSILON) {
                    $this->lastSkipped['no_demand']++;

                    continue;
                }

                $dailyRate = $soldInStockDays / $inStockDays;
                $stockAtEnd = max(0.0, $stock);

                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: $key,
                    metricKey: self::METRIC_KEY,
                    value: $stockAtEnd <= self::EPSILON ? 0.0 : $stockAtEnd / $dailyRate,
                    period: 'month:'.$month->start->format('Y-m'),
                    valueMeta: [
                        'stock_qty' => $stockAtEnd,
                        'daily_rate' => $dailyRate,
                        'in_stock_days' => $inStockDays,
                        'window_days' => $this->windowDays,
                    ],
                );
            }
        }

        return $records;
    }
}
