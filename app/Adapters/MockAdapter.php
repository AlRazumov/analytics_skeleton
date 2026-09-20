<?php

namespace App\Adapters;

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\Mock\MockScenarioConfig;
use App\Adapters\Mock\MockScenarioManifest;
use App\Adapters\Mock\NewYearSeasonalPattern;
use App\Adapters\Mock\SeasonalPattern;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\Product;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use App\Core\Domain\Warehouse;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Потоковый генератор реалистичных тестовых данных без привязки к
 * какому-либо реальному источнику (Bitrix24/1С). Если для генерации
 * не хватает чего-то из контракта DataSourceAdapter — это сигнал
 * пересмотреть контракт, а не обходить его здесь.
 *
 * Профиль масштаба ($profile) и seed ($seed) передаются через
 * конструктор, без Laravel-конфигурации. Каждый fetch*-метод
 * самодостаточен: он создаёт собственный детерминированный генератор
 * случайных чисел на основе $seed и своего имени (см. randomizerFor),
 * поэтому состояние не разделяется между вызовами и не кешируется —
 * порядок/состав предыдущих вызовов не влияет на результат следующего,
 * как того требует докблок DataSourceAdapter.
 *
 * Движения и остатки — срез одной детерминированной истории (окно
 * historyDays до historyEnd; см. manifest() для эталонных сценариев),
 * поэтому fetchStock() и fetchStockMovements() согласованы, а остаток
 * ни на одном складе не бывает отрицательным. Полный прогон Large-
 * профиля по всей истории — миллионы записей и десятки секунд.
 *
 * Одинаковые $profile + $seed + аргументы метода → идентичный
 * результат между прогонами (используется Random\Engine\Mt19937 с
 * явным сидом, не глобальный rand()/mt_rand()).
 */
final class MockAdapter implements DataSourceAdapter
{
    private const CATEGORIES = ['electronics', 'apparel', 'home', 'food', 'toys'];

    /** Конец окна истории движений по умолчанию (фиксирован ради детерминированности). */
    private const HISTORY_END = '2026-08-31';

    private const GAP_LENGTH = 21;

    private const GAP_RATE = 3;

    private const SPIKE_BASE_RATE = 5;

    private const SPIKE_MULTIPLIER = 8;

    private const SPIKE_DAYS = 3;

    /** @var list<SeasonalPattern> */
    private readonly array $seasonalPatterns;

    private readonly DateTimeImmutable $historyStart;

    private readonly int $historyDays;

    /** @var list<string> */
    private readonly array $warehouseIds;

    private readonly MockScenarioConfig $scenarios;

    /** @var array<string, array{int, int}> kind => [первый индекс товара, последний] */
    private readonly array $kindRanges;

    /** @var list<int> день года (1..366) для каждого дня окна истории */
    private readonly array $dayOfYear;

    public function __construct(
        private readonly MockDataProfile $profile = MockDataProfile::Medium,
        private readonly int $seed = 42,
        ?MockScenarioConfig $scenarios = null,
        ?DateTimeImmutable $historyEnd = null,
    ) {
        // Список из одного паттерна на сейчас — расширяемо: второй
        // сезонный паттерн добавляется сюда без переписывания
        // generate*-логики (см. докблок SeasonalPattern). Паттерны
        // применяются к сделкам; сезонность остатков — в movementsFor.
        $this->seasonalPatterns = [new NewYearSeasonalPattern];

        $this->scenarios = $scenarios ?? $profile->scenarios();
        $this->historyDays = $profile->historyDays();
        $this->warehouseIds = $profile->warehouseIds();
        $end = new DateTimeImmutable(($historyEnd ?? new DateTimeImmutable(self::HISTORY_END))->format('Y-m-d'));
        $this->historyStart = $end->modify('-'.($this->historyDays - 1).' days');

        $next = 1;
        $ranges = [];
        foreach (['dead' => $this->scenarios->deadCount, 'near_zero' => $this->scenarios->nearZeroCount,
            'gaps' => $this->scenarios->gapCount, 'spike' => $this->scenarios->spikeCount,
            'seasonal' => $this->scenarios->seasonalCount] as $kind => $count) {
            $ranges[$kind] = [$next, $next + $count - 1];
            $next += $count;
        }
        if ($next - 1 > $profile->productCount()) {
            throw new InvalidArgumentException('Сценарные товары не помещаются в профиль.');
        }
        $this->kindRanges = $ranges;

        $doy = [];
        for ($d = 0; $d < $this->historyDays; $d++) {
            $doy[] = (int) $this->historyStart->modify("+{$d} days")->format('z') + 1;
        }
        $this->dayOfYear = $doy;
    }

    public function fetchWarehouses(): iterable
    {
        foreach ($this->warehouseIds as $id) {
            yield new Warehouse(id: $id, name: 'Склад '.substr($id, strrpos($id, '-') + 1));
        }
    }

    public function fetchProducts(): iterable
    {
        $randomizer = $this->randomizerFor('products');
        $count = $this->profile->productCount();

        for ($i = 1; $i <= $count; $i++) {
            $category = self::CATEGORIES[$randomizer->getInt(0, count(self::CATEGORIES) - 1)];

            yield new Product(
                id: "prod-{$i}",
                name: "Product {$i}",
                category: $category,
            );
        }
    }

    /**
     * Как и остальные fetch*, отдаёт данные только в окне истории
     * (historyStart()..historyEnd() включительно по дате). Ограничивается
     * лишь выдача: генерация (поток случайных чисел, счётчик id) идёт по
     * запрошенному периоду как раньше, поэтому значения внутри окна не
     * меняются.
     */
    public function fetchDeals(DateRange $period): iterable
    {
        $windowStart = $this->historyStart;
        $windowEnd = $this->historyEnd()->setTime(23, 59, 59);
        $randomizer = $this->randomizerFor('deals');
        $productCount = $this->profile->productCount();
        $dealsPerMonth = $this->profile->dealsPerMonth();
        $counter = 0;

        foreach ($this->monthsIn($period) as [$monthStart, $rangeStart, $rangeEnd, $proportion]) {
            $seasonalMultiplier = $this->seasonalMultiplier((int) $monthStart->format('n'));
            $count = (int) round($dealsPerMonth * $proportion * $seasonalMultiplier);

            for ($i = 0; $i < $count; $i++) {
                $counter++;
                $productIndex = $randomizer->getInt(1, $productCount);
                // Сумма сделки: условный диапазон 5.00-500.00, ориентировочно.
                $amount = $randomizer->getInt(500, 50000) / 100;

                $deal = new Deal(
                    id: "deal-{$counter}",
                    productId: "prod-{$productIndex}",
                    amount: $amount,
                    date: $this->randomDateBetween($randomizer, $rangeStart, $rangeEnd),
                );

                if ($deal->date >= $windowStart && $deal->date <= $windowEnd) {
                    yield $deal;
                }
            }
        }
    }

    /**
     * Границы DateRange включительно по дате. Порядок: по товарам, внутри
     * товара по времени. Движения не зависят от запрошенного диапазона —
     * это срез одной и той же детерминированной истории
     * (см. historyStart()/historyEnd()); вне окна истории данных нет.
     */
    public function fetchStockMovements(DateRange $period): iterable
    {
        $fromDay = max(0, $this->dayIndex($period->start));
        $toDay = min($this->historyDays - 1, $this->dayIndex($period->end));

        if ($fromDay > $toDay) {
            return;
        }

        for ($i = 1; $i <= $this->profile->productCount(); $i++) {
            yield from $this->movementsFor($i, $fromDay, $toDay);
        }
    }

    /**
     * Остатки на конец дня $asOf (null — конец окна истории) для КАЖДОЙ
     * пары товар×склад (в т.ч. нулевые). Считаются суммой движений, то
     * есть строго согласованы с fetchStockMovements().
     */
    public function fetchStock(?DateTimeImmutable $asOf = null): iterable
    {
        $toDay = $asOf === null
            ? $this->historyDays - 1
            : min($this->historyDays - 1, $this->dayIndex($asOf));
        for ($i = 1; $i <= $this->profile->productCount(); $i++) {
            $quantities = array_fill_keys($this->warehouseIds, 0.0);

            if ($toDay >= 0) {
                foreach ($this->movementsFor($i, 0, $toDay) as $movement) {
                    $quantities[$movement->warehouseId] += $movement->quantity;
                }
            }

            foreach ($quantities as $warehouseId => $quantity) {
                yield new StockBalance("prod-{$i}", $warehouseId, $quantity);
            }
        }
    }

    public function capabilities(): array
    {
        return [AdapterCapability::StockMovements, AdapterCapability::StockSnapshots];
    }

    public function historyStart(): DateTimeImmutable
    {
        return $this->historyStart;
    }

    public function historyEnd(): DateTimeImmutable
    {
        return $this->historyStart->modify('+'.($this->historyDays - 1).' days');
    }

    /** Эталонные факты о сценариях (см. MockScenarioManifest). */
    public function manifest(): MockScenarioManifest
    {
        $ids = fn (string $kind): array => array_map(
            static fn (int $i): string => "prod-{$i}",
            $this->productIndexesOf($kind),
        );
        $date = fn (int $day): string => $this->historyStart->modify("+{$day} days")->format('Y-m-d');
        $warehouse = $this->warehouseIds[0];

        $dead = [];
        foreach ($ids('dead') as $id) {
            $dead[$id] = $date($this->historyDays - 1 - $this->scenarios->deadDays);
        }

        $nearZero = [];
        foreach ($this->productIndexesOf('near_zero') as $k => $i) {
            [$rate, $daysLeft] = $this->nearZeroParams($k);
            $nearZero["prod-{$i}"] = [
                'warehouse_id' => $warehouse,
                'daily_rate' => $rate,
                'stock_at_end' => $rate * $daysLeft,
                'days_to_zero' => $daysLeft,
            ];
        }

        $gaps = [];
        foreach ($ids('gaps') as $id) {
            $gaps[$id] = array_map(
                fn (int $start): array => ['from' => $date($start), 'to' => $date($start + self::GAP_LENGTH - 1)],
                $this->gapStarts(),
            );
        }

        $spikes = [];
        [$spikeFrom, $spikeTo] = $this->spikeWindow();
        foreach ($ids('spike') as $id) {
            $spikes[$id] = [
                'from' => $date($spikeFrom),
                'to' => $date($spikeTo),
                'multiplier' => self::SPIKE_MULTIPLIER,
                'baseline_daily' => self::SPIKE_BASE_RATE,
            ];
        }

        return new MockScenarioManifest(
            historyStart: $this->historyStart->format('Y-m-d'),
            historyEnd: $this->historyEnd()->format('Y-m-d'),
            seasonalProductIds: $ids('seasonal'),
            seasonPeakMonth: 12,
            seasonTroughMonth: 6,
            deadProducts: $dead,
            deadDays: $this->scenarios->deadDays,
            nearZeroProducts: $nearZero,
            gapProducts: $gaps,
            spikeProducts: $spikes,
            hasTransfers: count($this->warehouseIds) > 1,
        );
    }

    /**
     * Движения одного товара за дни [$fromDay..$toDay] (индексы от
     * начала окна истории). Состояние (остатки) всегда прогоняется с
     * нулевого дня, поэтому результат не зависит от $fromDay.
     *
     * @return Generator<StockMovement>
     */
    private function movementsFor(int $index, int $fromDay, int $toDay): Generator
    {
        $kind = $this->kindOf($index);
        // id — функция (товар, день, тип, склад): за день на складе бывает
        // не больше одного движения каждого типа, а от диапазона запроса
        // id зависеть не должен.
        $emit = function (int $day, int $hour, int $warehouse, StockMovementType $type, int $quantity, array $meta = []) use ($index): StockMovement {
            return new StockMovement(
                id: "mv-{$index}-{$day}-{$type->value}-{$warehouse}",
                productId: "prod-{$index}",
                warehouseId: $this->warehouseIds[$warehouse],
                quantity: (float) $quantity,
                type: $type,
                date: $this->historyStart->modify("+{$day} days")->setTime($hour, 0),
                meta: $meta,
            );
        };

        yield from match ($kind) {
            'near_zero' => $this->nearZeroMovements($index, $fromDay, $toDay, $emit),
            'gaps' => $this->gapMovements($fromDay, $toDay, $emit),
            'spike' => $this->spikeMovements($fromDay, $toDay, $emit),
            default => $this->regularMovements($index, $kind, $fromDay, $toDay, $emit),
        };
    }

    /**
     * Обычный товар (а также dead и seasonal — их отличия заданы
     * kind): случайные, но детерминированные по $seed продажи,
     * пополнение по точке заказа, перемещения между складами,
     * изредка списания и корректировки. В хронологическом порядке
     * внутри дня (приёмка 08, перемещение 10, продажа 12, списание 16,
     * корректировка 18) остаток ни на одном складе не уходит в минус.
     *
     * @return Generator<StockMovement>
     */
    private function regularMovements(int $index, string $kind, int $fromDay, int $toDay, callable $emit): Generator
    {
        $random = $this->randomizerFor("product:{$index}");
        $warehouseCount = count($this->warehouseIds);
        $seasonal = $kind === 'seasonal';
        $lambda = $seasonal ? 6 : $random->getInt(1, 5);
        $peak = $seasonal ? 1.8 : 1.0;
        $reorderPoint = (int) ceil($lambda * $peak * 7);
        $batch = (int) ceil($lambda * $peak * 30);
        $stock = array_fill(0, $warehouseCount, 0);

        $lastDay = $kind === 'dead' ? $this->historyDays - 1 - $this->scenarios->deadDays : $this->historyDays - 1;

        for ($day = 0; $day <= min($toDay, $lastDay); $day++) {
            if ($kind === 'dead' && $day === $lastDay) {
                // Последнее движение перед «смертью»: остаток гарантированно > 0.
                $stock[0] += 50;
                if ($day >= $fromDay) {
                    yield $emit($day, 8, 0, StockMovementType::Receipt, 50);
                }

                break;
            }

            for ($w = 0; $w < $warehouseCount; $w++) {
                if ($stock[$w] < $reorderPoint) {
                    $stock[$w] += $batch;
                    if ($day >= $fromDay) {
                        yield $emit($day, 8, $w, StockMovementType::Receipt, $batch);
                    }
                }
            }

            if ($warehouseCount > 1 && $random->getInt(1, 45) === 1) {
                $from = array_search(max($stock), $stock, true);
                $to = ($from + $random->getInt(1, $warehouseCount - 1)) % $warehouseCount;
                if ($stock[$from] >= 2) {
                    $quantity = $random->getInt(1, intdiv($stock[$from], 2));
                    $stock[$from] -= $quantity;
                    $stock[$to] += $quantity;
                    if ($day >= $fromDay) {
                        $meta = ['transfer_id' => "tr-{$index}-{$day}"];
                        yield $emit($day, 10, $from, StockMovementType::TransferOut, -$quantity, $meta);
                        yield $emit($day, 10, $to, StockMovementType::TransferIn, $quantity, $meta);
                    }
                }
            }

            $multiplier = $seasonal ? 1 + 0.8 * cos(2 * M_PI * ($this->dayOfYear[$day] - 355) / 365) : 1.0;
            if ($random->getInt(1, 100) <= 60) {
                $quantity = $random->getInt(1, max(1, (int) round(2 * $lambda * $multiplier)));
                $candidates = array_keys(array_filter($stock, static fn (int $s): bool => $s >= $quantity));
                if ($candidates !== []) {
                    $w = $candidates[$random->getInt(0, count($candidates) - 1)];
                    $stock[$w] -= $quantity;
                    if ($day >= $fromDay) {
                        yield $emit($day, 12, $w, StockMovementType::Sale, -$quantity);
                    }
                }
            }

            if ($random->getInt(1, 200) === 1) {
                $w = $random->getInt(0, $warehouseCount - 1);
                $quantity = $random->getInt(1, 3);
                if ($stock[$w] >= $quantity) {
                    $stock[$w] -= $quantity;
                    if ($day >= $fromDay) {
                        yield $emit($day, 16, $w, StockMovementType::Writeoff, -$quantity);
                    }
                }
            }

            if ($random->getInt(1, 300) === 1) {
                $w = $random->getInt(0, $warehouseCount - 1);
                $delta = $random->getInt(1, 3) * ($random->getInt(0, 1) === 1 ? 1 : -1);
                if ($stock[$w] + $delta >= 0) {
                    $stock[$w] += $delta;
                    if ($day >= $fromDay) {
                        yield $emit($day, 18, $w, StockMovementType::Adjustment, $delta);
                    }
                }
            }
        }
    }

    /**
     * Ровно daily_rate продаж в день на первом складе, одна приёмка
     * на старте — остаток на конец истории равен daily_rate × days_to_zero.
     *
     * @return Generator<StockMovement>
     */
    private function nearZeroMovements(int $index, int $fromDay, int $toDay, callable $emit): Generator
    {
        [$rate, $daysLeft] = $this->nearZeroParams(array_search($index, $this->productIndexesOf('near_zero'), true));

        yield from $this->constantSales($fromDay, $toDay, $emit, [
            [0, $this->historyDays, $rate, $rate * ($this->historyDays + $daysLeft)],
        ]);
    }

    /**
     * Продажи GAP_RATE/день сегментами; между сегментами — окна
     * GAP_LENGTH дней с нулевым остатком и без продаж.
     *
     * @return Generator<StockMovement>
     */
    private function gapMovements(int $fromDay, int $toDay, callable $emit): Generator
    {
        $segments = [];
        $start = 0;
        foreach ([...$this->gapStarts(), $this->historyDays] as $end) {
            $isLast = $end === $this->historyDays;
            $receipt = self::GAP_RATE * ($end - $start) + ($isLast ? self::GAP_RATE * 10 : 0);
            $segments[] = [$start, $end, self::GAP_RATE, $receipt];
            $start = $end + self::GAP_LENGTH;
        }

        yield from $this->constantSales($fromDay, $toDay, $emit, $segments);
    }

    /**
     * @return Generator<StockMovement>
     */
    private function spikeMovements(int $fromDay, int $toDay, callable $emit): Generator
    {
        [$spikeFrom, $spikeTo] = $this->spikeWindow();
        $spikeDays = $spikeTo - $spikeFrom + 1;
        $receipt = self::SPIKE_BASE_RATE * ($this->historyDays + $spikeDays * (self::SPIKE_MULTIPLIER - 1) + 30);

        yield from $this->constantSales($fromDay, $toDay, $emit, [[0, $this->historyDays, self::SPIKE_BASE_RATE, $receipt]], function (int $day) use ($spikeFrom, $spikeTo): int {
            return $day >= $spikeFrom && $day <= $spikeTo ? self::SPIKE_MULTIPLIER : 1;
        });
    }

    /**
     * Сегменты [первый день, день после последнего, продажи/день,
     * приёмка в первый день] на первом складе.
     *
     * @param  list<array{int, int, int, int}>  $segments
     * @return Generator<StockMovement>
     */
    private function constantSales(int $fromDay, int $toDay, callable $emit, array $segments, ?callable $factor = null): Generator
    {
        foreach ($segments as [$start, $end, $rate, $receipt]) {
            if ($start > $toDay) {
                return;
            }

            if ($start >= $fromDay) {
                yield $emit($start, 8, 0, StockMovementType::Receipt, $receipt);
            }

            for ($day = max($start, $fromDay); $day < min($end, $toDay + 1); $day++) {
                yield $emit($day, 12, 0, StockMovementType::Sale, -$rate * ($factor === null ? 1 : $factor($day)));
            }
        }
    }

    /** @return array{int, int} [daily_rate, days_to_zero] для k-го near_zero-товара. */
    private function nearZeroParams(int $k): array
    {
        return [2 + $k % 3, 3 + $k % 4];
    }

    /** @return list<int> индексы дней начала окон нулевого остатка */
    private function gapStarts(): array
    {
        return [intdiv($this->historyDays, 4), intdiv($this->historyDays, 2), intdiv($this->historyDays * 3, 4)];
    }

    /** @return array{int, int} */
    private function spikeWindow(): array
    {
        $from = intdiv($this->historyDays * 3, 5);

        return [$from, $from + self::SPIKE_DAYS - 1];
    }

    private function kindOf(int $index): string
    {
        foreach ($this->kindRanges as $kind => [$first, $last]) {
            if ($index >= $first && $index <= $last) {
                return $kind;
            }
        }

        return 'regular';
    }

    /** @return list<int> */
    private function productIndexesOf(string $kind): array
    {
        [$first, $last] = $this->kindRanges[$kind];

        return $first > $last ? [] : range($first, $last);
    }

    /** Индекс дня от начала окна истории (может быть < 0 или ≥ длины). */
    private function dayIndex(DateTimeImmutable $date): int
    {
        $day = new DateTimeImmutable($date->format('Y-m-d'));

        return (int) $this->historyStart->diff($day)->format('%r%a');
    }

    private function seasonalMultiplier(int $month): float
    {
        $multiplier = 1.0;
        foreach ($this->seasonalPatterns as $pattern) {
            $multiplier *= $pattern->multiplierForMonth($month);
        }

        return $multiplier;
    }

    /**
     * Разбивает период на месяцы, пересекающиеся с ним.
     *
     * @return Generator<array{DateTimeImmutable, DateTimeImmutable, DateTimeImmutable, float}>
     *                                                                                          [начало месяца, начало пересечения с периодом, конец
     *                                                                                          пересечения с периодом, доля месяца, попавшая в период]
     */
    private function monthsIn(DateRange $period): Generator
    {
        $cursor = new DateTimeImmutable($period->start->format('Y-m-01'));
        $lastMonth = new DateTimeImmutable($period->end->format('Y-m-01'));

        while ($cursor <= $lastMonth) {
            $daysInMonth = (int) $cursor->format('t');
            $monthEnd = $cursor->modify('+1 month -1 second');
            $rangeStart = $cursor > $period->start ? $cursor : $period->start;
            $rangeEnd = $monthEnd < $period->end ? $monthEnd : $period->end;
            $daysInRange = max(1, $rangeStart->diff($rangeEnd)->days + 1);
            $proportion = min(1.0, $daysInRange / $daysInMonth);

            yield [$cursor, $rangeStart, $rangeEnd, $proportion];

            $cursor = $cursor->modify('+1 month');
        }
    }

    private function randomDateBetween(Randomizer $randomizer, DateTimeImmutable $start, DateTimeImmutable $end): DateTimeImmutable
    {
        $startTs = $start->getTimestamp();
        $endTs = max($startTs, $end->getTimestamp());

        return (new DateTimeImmutable)->setTimestamp($randomizer->getInt($startTs, $endTs));
    }

    /**
     * Отдельный, детерминированный по ($seed, $context) генератор
     * случайных чисел для каждого fetch*-метода — чтобы вызовы не
     * разделяли состояние друг с другом (см. докблок класса) и при
     * этом давали разные (не идентичные) потоки данных.
     */
    private function randomizerFor(string $context): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($this->seed.':'.$context)));
    }
}
