<?php

namespace App\Adapters;

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\Mock\NewYearSeasonalPattern;
use App\Adapters\Mock\SeasonalPattern;
use App\Adapters\Mock\SingleSpikeAnomaly;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\Product;
use App\Core\Domain\StockMovement;
use DateTimeImmutable;
use Generator;
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
 * Одинаковые $profile + $seed + аргументы метода → идентичный
 * результат между прогонами (используется Random\Engine\Mt19937 с
 * явным сидом, не глобальный rand()/mt_rand()).
 */
final class MockAdapter implements DataSourceAdapter
{
    private const CATEGORIES = ['electronics', 'apparel', 'home', 'food', 'toys'];

    /**
     * Заготовка дисбаланс-сценария (см. fetchStockMovements): товар и
     * пара складов из этого сценария зафиксированы явно (не зависят от
     * $seed), чтобы сценарий было легко найти и проверить в тестах.
     */
    private const IMBALANCE_PRODUCT_ID = 'prod-1';

    // Значения намеренно на порядок больше типичного фонового
    // движения по товару за месяц, чтобы сигнал был безусловно
    // различим на фоне случайных движений того же товара, попавших в
    // те же склады. Величины ОРИЕНТИРОВОЧНЫЕ.
    private const IMBALANCE_OVERSTOCK_QTY = 5000.0;

    private const IMBALANCE_UNDERSTOCK_QTY = 4800.0;

    /** Доля движений склада, генерируемая как Transfer. Ориентировочно. */
    private const TRANSFER_SHARE = 0.05;

    /** @var list<SeasonalPattern> */
    private readonly array $seasonalPatterns;

    public function __construct(
        private readonly MockDataProfile $profile = MockDataProfile::Medium,
        private readonly int $seed = 42,
    ) {
        // Список из одного паттерна на сейчас — расширяемо: второй
        // сезонный паттерн добавляется сюда без переписывания
        // generate*-логики (см. докблок SeasonalPattern).
        $this->seasonalPatterns = [new NewYearSeasonalPattern];
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

    public function fetchDeals(DateRange $period): iterable
    {
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

                yield new Deal(
                    id: "deal-{$counter}",
                    productId: "prod-{$productIndex}",
                    amount: $amount,
                    date: $this->randomDateBetween($randomizer, $rangeStart, $rangeEnd),
                );
            }
        }
    }

    public function fetchStockMovements(DateRange $period): iterable
    {
        $randomizer = $this->randomizerFor('stock');
        $productCount = $this->profile->productCount();
        $warehouseIds = $this->profile->warehouseIds();
        $warehouseCount = count($warehouseIds);
        $counter = 0;

        // Аномалия: товар выбирается детерминированно по $seed один раз
        // за вызов (не зависит от того, сколько элементов уже прочитано
        // из потока), затрагивает первый месяц запрошенного периода.
        $anomalyProductIndex = $randomizer->getInt(1, $productCount);
        $firstMonth = new DateTimeImmutable($period->start->format('Y-m-01'));
        $anomaly = new SingleSpikeAnomaly(
            productId: "prod-{$anomalyProductIndex}",
            year: (int) $firstMonth->format('Y'),
            month: (int) $firstMonth->format('n'),
        );

        $isFirstMonth = true;

        foreach ($this->monthsIn($period) as [$monthStart, $rangeStart, $rangeEnd, $proportion]) {
            $seasonalMultiplier = $this->seasonalMultiplier((int) $monthStart->format('n'));

            if ($isFirstMonth) {
                yield from $this->imbalanceScenario($warehouseIds, $rangeStart, $counter);
            }
            $isFirstMonth = false;

            foreach ($warehouseIds as $warehouseId) {
                // Объём движений по складу условно завязан на
                // dealsPerMonth профиля, поделённый на число складов —
                // ориентировочная пропорция, не откалиброванная под
                // реальный товарооборот (см. докблок MockDataProfile).
                $baseCount = (int) round(
                    ($this->profile->dealsPerMonth() / $warehouseCount) * $proportion * $seasonalMultiplier
                );

                for ($i = 0; $i < $baseCount; $i++) {
                    $counter++;
                    $productIndex = $randomizer->getInt(1, $productCount);
                    $productId = "prod-{$productIndex}";
                    $date = $this->randomDateBetween($randomizer, $rangeStart, $rangeEnd);
                    $type = $randomizer->getInt(1, 100) <= 55
                        ? StockMovementType::Out
                        : StockMovementType::In;
                    $quantity = $randomizer->getInt(1, 50);

                    if ($type === StockMovementType::Out) {
                        $spike = $anomaly->multiplierFor($productId, $date);
                        if ($spike !== null) {
                            $quantity = (int) round($quantity * $spike);
                        }
                    }

                    yield new StockMovement(
                        id: "stock-{$counter}",
                        productId: $productId,
                        warehouseId: $warehouseId,
                        quantity: (float) $quantity,
                        type: $type,
                        date: $date,
                    );
                }

                if ($warehouseCount > 1) {
                    yield from $this->transfers(
                        $randomizer,
                        $warehouseId,
                        $warehouseIds,
                        $productCount,
                        $rangeStart,
                        $rangeEnd,
                        (int) round($baseCount * self::TRANSFER_SHARE),
                        $counter,
                    );
                }
            }
        }
    }

    /**
     * Заготовка дисбаланс-сценария межфилиального перемещения (только
     * Large-профиль, только первый месяц запрошенного периода): склад
     * warehouseIds[0] явно затоварен, warehouseIds[1] явно в дефиците
     * по одному и тому же товару. Товар/пара складов зафиксированы
     * явно (не зависят от $seed), чтобы сценарий был легко находим в
     * тестах. Количества ОРИЕНТИРОВОЧНЫЕ — важна структура сигнала
     * (резкий разнонаправленный дисбаланс между двумя складами по
     * одному товару в одном периоде), а не точная величина.
     *
     * @param  list<string>  $warehouseIds
     * @return Generator<StockMovement>
     */
    private function imbalanceScenario(array $warehouseIds, DateTimeImmutable $date, int &$counter): Generator
    {
        if ($this->profile !== MockDataProfile::Large || count($warehouseIds) < 2) {
            return;
        }

        $counter++;
        yield new StockMovement(
            id: "stock-imbalance-in-{$counter}",
            productId: self::IMBALANCE_PRODUCT_ID,
            warehouseId: $warehouseIds[0],
            quantity: self::IMBALANCE_OVERSTOCK_QTY,
            type: StockMovementType::In,
            date: $date,
        );

        $counter++;
        yield new StockMovement(
            id: "stock-imbalance-out-{$counter}",
            productId: self::IMBALANCE_PRODUCT_ID,
            warehouseId: $warehouseIds[1],
            quantity: self::IMBALANCE_UNDERSTOCK_QTY,
            type: StockMovementType::Out,
            date: $date,
        );
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Generator<StockMovement>
     */
    private function transfers(
        Randomizer $randomizer,
        string $fromWarehouseId,
        array $warehouseIds,
        int $productCount,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        int $count,
        int &$counter,
    ): Generator {
        $warehouseCount = count($warehouseIds);

        for ($i = 0; $i < $count; $i++) {
            $counter++;
            $toWarehouseId = $fromWarehouseId;
            while ($toWarehouseId === $fromWarehouseId) {
                $toWarehouseId = $warehouseIds[$randomizer->getInt(0, $warehouseCount - 1)];
            }

            $productIndex = $randomizer->getInt(1, $productCount);

            yield new StockMovement(
                id: "stock-transfer-{$counter}",
                productId: "prod-{$productIndex}",
                warehouseId: $fromWarehouseId,
                quantity: (float) $randomizer->getInt(1, 30),
                type: StockMovementType::Transfer,
                date: $this->randomDateBetween($randomizer, $rangeStart, $rangeEnd),
                toWarehouseId: $toWarehouseId,
            );
        }
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
