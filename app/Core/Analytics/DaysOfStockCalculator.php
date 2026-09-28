<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\Period;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;

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
 * Остаток по дням окна: остаток накануне окна + движения окна (все
 * типы, только эта пара). Продажи вне окна и другие типы спросом не
 * считаются.
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
 * Два обращения к источнику на весь диапазон, сколько бы в нём ни было
 * месяцев: fetchStock накануне первого окна и fetchStockMovements от
 * начала первого окна до конца диапазона; остаток накануне каждого
 * следующего окна — прокруткой движений (опирается на правило контракта
 * stock.movements_balance). В памяти — движения диапазона, свёрнутые до
 * сумм по (пара, день), а не сами движения.
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
        $records = [];
        foreach ($this->calculateByMonth($adapter, $period) as $chunk) {
            array_push($records, ...$chunk);
        }

        return $records;
    }

    /**
     * То же, что calculate(), порцией на каждый месяц диапазона (в памяти —
     * записи одного месяца). $lastSkipped окончателен после полного обхода.
     *
     * @return Generator<int, MetricsSnapshotRecord[]>
     */
    public function calculateByMonth(DataSourceAdapter $adapter, DateRange $period): Generator
    {
        $rangeStart = new DateTimeImmutable($period->start->format('Y-m-d'));
        $rangeEnd = new DateTimeImmutable($period->end->format('Y-m-d'));
        $this->lastSkipped = ['no_demand' => 0, 'too_few_in_stock_days' => 0];

        /** @var list<array{Period, DateTimeImmutable}> $windows [месяц, начало окна] */
        $windows = [];
        foreach (Months::in($rangeStart, $rangeEnd) as $month) {
            $asOf = min($month->end, $rangeEnd);
            $windows[] = [$month, $asOf->modify('-'.($this->windowDays - 1).' days')];
        }
        if ($windows === []) {
            return;
        }

        // Дни считаются от начала первого окна. Окна идут по возрастанию
        // начала, но могут перекрываться (последний месяц, обрезанный концом
        // диапазона) и оставлять между собой дни вне окон — движения этих дней
        // тоже входят в прокрутку остатка.
        $firstDay = $windows[0][1];
        $lastDayIndex = self::dayIndex($firstDay, $rangeEnd);

        // Остаток на начало дня $cursor (изначально — на конец дня накануне
        // первого окна).
        $stock = [];
        foreach ($adapter->fetchStock($firstDay->modify('-1 day')) as $balance) {
            $stock[ProductWarehouseKey::make($balance->productId, $balance->warehouseId)] = $balance->quantity;
        }
        $cursor = 0;

        $saleByDay = [];
        $deltaByDay = [];
        foreach ($adapter->fetchStockMovements(new DateRange($firstDay, $rangeEnd)) as $movement) {
            $key = ProductWarehouseKey::make($movement->productId, $movement->warehouseId);
            $dayIndex = self::dayIndex($firstDay, $movement->date);
            if ($dayIndex < 0 || $dayIndex > $lastDayIndex) {
                continue;
            }

            $deltaByDay[$key][$dayIndex] = ($deltaByDay[$key][$dayIndex] ?? 0.0) + $movement->quantity;
            if ($movement->type === StockMovementType::Sale) {
                $saleByDay[$key][$dayIndex] = ($saleByDay[$key][$dayIndex] ?? 0.0) - $movement->quantity;
            }
        }

        $keys = array_keys($stock + $deltaByDay);
        sort($keys, SORT_STRING);

        foreach ($windows as [$month, $windowStart]) {
            $records = [];
            $from = self::dayIndex($firstDay, $windowStart);

            foreach ($keys as $key) {
                $deltas = $deltaByDay[$key] ?? [];
                $opening = $stock[$key] ?? 0.0;
                for ($day = $cursor; $day < $from; $day++) {
                    $opening += $deltas[$day] ?? 0.0;
                }
                $stock[$key] = $opening;

                $sales = $saleByDay[$key] ?? [];
                $hasSale = false;
                for ($day = $from; $day < $from + $this->windowDays && ! $hasSale; $day++) {
                    $hasSale = isset($sales[$day]);
                }
                if (! $hasSale) {
                    $record = $this->noDemand($key, $month, $opening, $deltas, $from);
                    if ($record !== null) {
                        $records[] = $record;
                    }

                    continue;
                }

                $stockOnDay = $opening;
                $inStockDays = 0;
                $soldInStockDays = 0.0;
                for ($day = $from; $day < $from + $this->windowDays; $day++) {
                    if ($stockOnDay > self::EPSILON) {
                        $inStockDays++;
                        $soldInStockDays += $sales[$day] ?? 0.0;
                    }
                    $stockOnDay += $deltas[$day] ?? 0.0;
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
                $stockAtEnd = max(0.0, $stockOnDay);

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

            $cursor = $from;

            yield $records;
        }
    }

    /**
     * Пара без продаж в окне — и с остатком на начало окна, и получившая
     * товар внутри окна (приход/перемещение на склад с нуля): пропуск
     * no_demand и stock_no_demand при положительном остатке на конец окна.
     *
     * @param  array<int, float>  $deltas  движения пары по дням от начала первого окна
     */
    private function noDemand(string $key, Period $month, float $opening, array $deltas, int $from): ?MetricsSnapshotRecord
    {
        $finalStock = $opening;
        for ($day = $from; $day < $from + $this->windowDays; $day++) {
            $finalStock += $deltas[$day] ?? 0.0;
        }
        $finalStock = max(0.0, $finalStock);

        if ($opening <= self::EPSILON && $finalStock <= self::EPSILON) {
            return null;
        }

        $this->lastSkipped['no_demand']++;
        if ($finalStock <= self::EPSILON) {
            return null;
        }

        return new MetricsSnapshotRecord(
            entityType: self::ENTITY_TYPE,
            entityId: $key,
            metricKey: self::NO_DEMAND_STOCK_METRIC_KEY,
            value: $finalStock,
            period: 'month:'.$month->start->format('Y-m'),
            valueMeta: ['stock_qty' => $finalStock],
        );
    }

    /** Номер дня $date (время игнорируется) от $firstDay; до него — отрицательный. */
    private static function dayIndex(DateTimeImmutable $firstDay, DateTimeInterface $date): int
    {
        return (int) $firstDay->diff(new DateTimeImmutable($date->format('Y-m-d')))->format('%r%a');
    }
}
