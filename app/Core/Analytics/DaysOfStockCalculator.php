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
 *   спрос/день = сумма продаж (sale) в окне W дней, заканчивающемся на
 *                asOf / число дней окна, в которые остаток НА НАЧАЛО
 *                дня был > 0
 *   значение   = остаток на asOf / спрос/день
 *
 * Дни с нулевым остатком не входят в знаменатель, иначе провалы по
 * остатку занижали бы скорость продаж и завышали «дни до обнуления».
 * Остаток по дням окна: fetchStock(начало окна − 1 день) + движения
 * окна (все типы, только эта пара). Продажи вне окна и другие типы
 * спросом не считаются.
 *
 * Снэпшот НЕ пишется (а не 0 и не бесконечность), если спроса нет или
 * дней с остатком в окне меньше min_in_stock_days; счётчики пропусков
 * доступны в $lastSkipped. Остаток на asOf = 0 при наличии спроса даёт 0.
 * value_meta: stock_qty, daily_rate, in_stock_days, window_days.
 *
 * ОГРАНИЧЕНИЕ: сезонность не учитывается — окно короткое, скорость
 * считается плоской. Побочный эффект определения «остаток на начало
 * дня»: в день прихода (приёмка утром, остаток на начало дня 0)
 * продажи этого дня попадают в числитель, а день — не в знаменатель,
 * поэтому сразу после провала скорость слегка завышена.
 *
 * Читает окно отдельным вызовом fetchStock/fetchStockMovements на
 * каждый месяц (потоково; в памяти — движения одного окна).
 * Требует capabilities StockMovements и StockSnapshots.
 */
final class DaysOfStockCalculator
{
    public const ENTITY_TYPE = ProductWarehouseKey::ENTITY_TYPE;

    public const METRIC_KEY = 'days_of_stock';

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

            $sales = [];
            $deltaByDay = [];
            foreach ($adapter->fetchStockMovements($window) as $movement) {
                $key = ProductWarehouseKey::make($movement->productId, $movement->warehouseId);
                $dayIndex = (int) $windowStart->diff(new DateTimeImmutable($movement->date->format('Y-m-d')))->format('%r%a');
                if ($dayIndex < 0 || $dayIndex >= $this->windowDays) {
                    continue;
                }

                $deltaByDay[$key][$dayIndex] = ($deltaByDay[$key][$dayIndex] ?? 0.0) + $movement->quantity;
                if ($movement->type === StockMovementType::Sale) {
                    $sales[$key] = ($sales[$key] ?? 0.0) - $movement->quantity;
                }
            }

            foreach ($opening as $key => $quantity) {
                if ($quantity > self::EPSILON && ! isset($sales[$key])) {
                    $this->lastSkipped['no_demand']++;
                }
            }

            ksort($sales);
            foreach ($sales as $key => $soldTotal) {
                if ($soldTotal <= self::EPSILON) {
                    $this->lastSkipped['no_demand']++;

                    continue;
                }

                $stock = $opening[$key] ?? 0.0;
                $inStockDays = 0;
                for ($day = 0; $day < $this->windowDays; $day++) {
                    if ($stock > self::EPSILON) {
                        $inStockDays++;
                    }
                    $stock += $deltaByDay[$key][$day] ?? 0.0;
                }

                if ($inStockDays < $this->minInStockDays) {
                    $this->lastSkipped['too_few_in_stock_days']++;

                    continue;
                }

                $dailyRate = $soldTotal / $inStockDays;
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
