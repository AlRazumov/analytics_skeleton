<?php

namespace App\Adapters\Contract;

use App\Core\Analytics\Sellers\SellerSalesData;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Contracts\ProvidesHistoryBounds;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Product;
use App\Core\Domain\Seller;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use App\Core\Domain\Warehouse;
use DateTimeImmutable;
use Throwable;

/**
 * Проверка любой реализации DataSourceAdapter на правила, на которые
 * опирается ядро (список и смысл — docs/adapter-contract.md). Ничего не
 * пишет и не кеширует: только вызывает fetch*-методы на диапазоне $range
 * и сверяет результаты между собой. Используется тестами (мок и будущие
 * адаптеры) и командой adapter:check — для проверки реального источника.
 *
 * Правила сформулированы для неизменных за время проверки данных: если
 * в источнике идёт запись, deals.repeatable/deals.split могут ложно
 * сработать — проверять лучше закрытый период.
 */
final class AdapterContractChecker
{
    public const int MAX_EXAMPLES = 5;

    private const float EPSILON = 1e-6;

    /** @var array<string, array{message: string, count: int, examples: list<string>}> */
    private array $found = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function check(DataSourceAdapter $adapter, DateRange $range): ContractReport
    {
        $this->found = [];
        $this->skipped = [];
        $this->counts = ['deals' => 0, 'products' => 0, 'warehouses' => 0, 'sellers' => 0, 'movements' => 0, 'balances' => 0];

        $capabilities = $this->guard('capabilities', fn () => $this->capabilities($adapter)) ?? [];
        $products = $this->guard('products', fn () => $this->reference('products', self::untrusted($adapter->fetchProducts()), Product::class)) ?? [];
        $warehouses = $this->guard('warehouses', fn () => $this->reference('warehouses', self::untrusted($adapter->fetchWarehouses()), Warehouse::class)) ?? [];
        $sellers = $this->guard('sellers', fn () => $this->reference('sellers', self::untrusted($adapter->fetchSellers()), Seller::class)) ?? [];

        $this->guard('deals', fn () => $this->deals($adapter, $range, $products, $sellers));

        $movementSums = null;
        if (in_array(AdapterCapability::StockMovements, $capabilities, true)) {
            $movementSums = $this->guard('movements', fn () => $this->movements($adapter, $range, $products, $warehouses));
        } else {
            $this->skipped[] = 'movements.*: нет capability stock_movements';
        }

        if (in_array(AdapterCapability::StockSnapshots, $capabilities, true)) {
            $this->guard('stock', fn () => $this->stock($adapter, $range, $products, $warehouses, $movementSums));
        } else {
            $this->skipped[] = 'stock.*: нет capability stock_snapshots';
        }

        if ($adapter instanceof ProvidesHistoryBounds) {
            $this->guard('history', fn () => $this->historyBounds($adapter, $capabilities));
        } else {
            $this->skipped[] = 'history.bounds: адаптер не реализует ProvidesHistoryBounds (для реального источника это нормально)';
        }

        $violations = [];
        foreach ($this->found as $rule => $f) {
            $violations[] = new ContractViolation($rule, $f['message'], $f['count'], $f['examples']);
        }

        return new ContractReport($violations, $this->counts, $this->skipped);
    }

    /** @return list<AdapterCapability> */
    private function capabilities(DataSourceAdapter $adapter): array
    {
        $list = self::untrustedValue($adapter->capabilities());
        $valid = [];
        foreach (is_array($list) ? $list : [] as $c) {
            if ($c instanceof AdapterCapability) {
                $valid[] = $c;
            }
        }

        if (! is_array($list) || ! array_is_list($list) || count($valid) !== count($list)) {
            $this->fail('capabilities', 'capabilities() должен возвращать list<AdapterCapability>');
        }
        if (count(array_unique(array_map(static fn (AdapterCapability $c) => $c->value, $valid))) !== count($valid)) {
            $this->fail('capabilities', 'capabilities() содержит повторы');
        }

        return $valid;
    }

    /**
     * Справочник: элементы нужного класса, непустые уникальные id, непустые названия.
     *
     * @template T of Product|Warehouse|Seller
     *
     * @param  iterable<mixed>  $items
     * @param  class-string<T>  $class
     * @return array<array-key, true> множество id (числовые id PHP хранит как int-ключи)
     */
    private function reference(string $name, iterable $items, string $class): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (! $item instanceof $class) {
                $this->fail("{$name}.type", "элемент {$name} — не ".class_basename($class), get_debug_type($item));

                continue;
            }
            $this->counts[$name]++;
            if ($item->id === '' || isset($ids[$item->id])) {
                $this->fail("{$name}.ids", "id в {$name} пустой или повторяется", $item->id === '' ? '(пусто)' : $item->id);
            }
            if (trim($item->name) === '') {
                $this->fail("{$name}.names", "пустое название в {$name}", $item->id);
            }
            $ids[$item->id] = true;
        }

        return $ids;
    }

    /**
     * @param  array<array-key, true>  $products
     * @param  array<array-key, true>  $sellers
     */
    private function deals(DataSourceAdapter $adapter, DateRange $range, array $products, array $sellers): void
    {
        [$from, $to] = [$this->day($range->start), $this->day($range->end)];
        $withSeller = 0;
        $withoutSeller = 0;
        $first = [];
        $days = [];

        foreach (self::untrusted($adapter->fetchDeals($range)) as $deal) {
            if (! $deal instanceof Deal) {
                $this->fail('deals.type', 'элемент fetchDeals() — не Deal', get_debug_type($deal));

                continue;
            }
            $this->counts['deals']++;

            if ($deal->id === '' || isset($first[$deal->id])) {
                $this->fail('deals.ids', 'id сделки пустой или повторяется в одном вызове', $deal->id === '' ? '(пусто)' : $deal->id);
            }
            $first[$deal->id] = $this->dealPrint($deal);

            $day = $days[$deal->id] = $this->day($deal->date);
            if ($day < $from || $day > $to) {
                $this->fail('deals.range', "сделка вне запрошенного диапазона {$from}..{$to}", "{$deal->id} ({$day})");
            }
            if (! is_finite($deal->amount)) {
                $this->fail('deals.amount', 'сумма сделки не конечное число', $deal->id);
            }
            if (! isset($products[$deal->productId])) {
                $this->fail('deals.products', 'productId сделки нет в fetchProducts()', "{$deal->id} → {$deal->productId}");
            }

            if ($deal->sellerId === null) {
                $withoutSeller++;
            } else {
                $withSeller++;
                if ($deal->sellerId === '' || $deal->sellerId === SellerSalesData::NO_SELLER) {
                    $this->fail('sellers.on_deals', 'sellerId сделки пустой или зарезервирован ('.SellerSalesData::NO_SELLER.'); «без продавца» — это null', $deal->id);
                } elseif (! isset($sellers[$deal->sellerId])) {
                    $this->fail('sellers.on_deals', 'sellerId сделки нет в fetchSellers()', "{$deal->id} → {$deal->sellerId}");
                }
            }
        }

        $this->sellerCoverage($adapter->sellerCoverage(), $withSeller, $withoutSeller);

        $this->sameSet('deals.repeatable', 'повторный fetchDeals() на том же диапазоне вернул другие сделки', $first, $this->prints(self::untrusted($adapter->fetchDeals($range)), Deal::class, $this->dealPrint(...)));

        $this->boundaries('deals.split', $range, $first, $days, fn (DateRange $r) => self::untrusted($adapter->fetchDeals($r)), Deal::class, $this->dealPrint(...));
    }

    private function sellerCoverage(SellerCoverage $coverage, int $withSeller, int $withoutSeller): void
    {
        // Full и Partial по данным не различить (см. SellerCoverage): оба
        // обещают продавца хотя бы у части сделок. На пустом диапазоне
        // проверять нечего.
        $problem = match ($coverage) {
            SellerCoverage::None => $withSeller > 0 ? "sellerCoverage()=none, но у {$withSeller} сделок есть sellerId" : null,
            SellerCoverage::Full, SellerCoverage::Partial => $withSeller === 0 && $withoutSeller > 0
                ? "sellerCoverage()={$coverage->value}, но ни у одной из {$withoutSeller} сделок нет sellerId"
                : null,
        };
        if ($problem !== null) {
            $this->fail('sellers.coverage', $problem);
        }
    }

    /**
     * @param  array<array-key, true>  $products
     * @param  array<array-key, true>  $warehouses
     * @return array<string, float> сумма движений диапазона по паре "product|warehouse"
     */
    private function movements(DataSourceAdapter $adapter, DateRange $range, array $products, array $warehouses): array
    {
        [$from, $to] = [$this->day($range->start), $this->day($range->end)];
        $first = [];
        $days = [];
        $sums = [];

        foreach (self::untrusted($adapter->fetchStockMovements($range)) as $m) {
            if (! $m instanceof StockMovement) {
                $this->fail('movements.type', 'элемент fetchStockMovements() — не StockMovement', get_debug_type($m));

                continue;
            }
            $this->counts['movements']++;

            if ($m->id === '' || isset($first[$m->id])) {
                $this->fail('movements.ids', 'id движения пустой или повторяется в одном вызове', $m->id === '' ? '(пусто)' : $m->id);
            }
            $first[$m->id] = $this->movementPrint($m);

            $day = $days[$m->id] = $this->day($m->date);
            if ($day < $from || $day > $to) {
                $this->fail('movements.range', "движение вне запрошенного диапазона {$from}..{$to}", "{$m->id} ({$day})");
            }
            if (! is_finite($m->quantity)) {
                $this->fail('movements.quantity', 'количество движения не конечное число', $m->id);
            }
            if (! isset($products[$m->productId])) {
                $this->fail('movements.products', 'productId движения нет в fetchProducts()', "{$m->id} → {$m->productId}");
            }
            if ($warehouses !== [] && ! isset($warehouses[$m->warehouseId])) {
                $this->fail('movements.warehouses', 'warehouseId движения нет в fetchWarehouses()', "{$m->id} → {$m->warehouseId}");
            }

            $key = "{$m->productId}|{$m->warehouseId}";
            $sums[$key] = ($sums[$key] ?? 0.0) + $m->quantity;
        }

        $this->sameSet('movements.repeatable', 'повторный fetchStockMovements() на том же диапазоне вернул другие движения', $first, $this->prints(self::untrusted($adapter->fetchStockMovements($range)), StockMovement::class, $this->movementPrint(...)));

        $this->boundaries('movements.split', $range, $first, $days, fn (DateRange $r) => self::untrusted($adapter->fetchStockMovements($r)), StockMovement::class, $this->movementPrint(...));

        return $sums;
    }

    /**
     * @param  array<array-key, true>  $products
     * @param  array<array-key, true>  $warehouses
     * @param  array<string, float>|null  $movementSums  null — движений нет (capability или ошибка)
     */
    private function stock(DataSourceAdapter $adapter, DateRange $range, array $products, array $warehouses, ?array $movementSums): void
    {
        $endDay = new DateTimeImmutable($this->day($range->end));

        $close = $this->balances(self::untrusted($adapter->fetchStock($endDay)), $products, $warehouses, count: true);
        $this->balances(self::untrusted($adapter->fetchStock(null)), $products, $warehouses);

        $lateInDay = $this->balances(self::untrusted($adapter->fetchStock($endDay->setTime(23, 59, 59))), [], []);
        $this->sameBalances('stock.time_ignored', 'fetchStock() на начало и на конец одного дня вернул разные остатки (время должно игнорироваться)', $close, $lateInDay);

        if ($movementSums === null) {
            $this->skipped[] = 'stock.movements_balance: нет движений для сверки';

            return;
        }

        $open = $this->balances(self::untrusted($adapter->fetchStock($this->startDay($range)->modify('-1 day'))), [], []);
        $expected = $open;
        foreach ($movementSums as $key => $sum) {
            $expected[$key] = ($expected[$key] ?? 0.0) + $sum;
        }
        $this->sameBalances('stock.movements_balance', 'остаток на конец диапазона ≠ остаток накануне начала + сумма движений диапазона', $expected, $close);
    }

    /**
     * @param  iterable<mixed>  $items
     * @param  array<array-key, true>  $products  пусто — не сверять
     * @param  array<array-key, true>  $warehouses  пусто — не сверять
     * @return array<string, float> "product|warehouse" => количество
     */
    private function balances(iterable $items, array $products, array $warehouses, bool $count = false): array
    {
        $result = [];
        foreach ($items as $b) {
            if (! $b instanceof StockBalance) {
                $this->fail('stock.type', 'элемент fetchStock() — не StockBalance', get_debug_type($b));

                continue;
            }
            if ($count) {
                $this->counts['balances']++;
            }
            $key = "{$b->productId}|{$b->warehouseId}";
            if (isset($result[$key])) {
                $this->fail('stock.pairs', 'пара товар × склад повторяется в одном вызове fetchStock()', $key);
            }
            if (! is_finite($b->quantity)) {
                $this->fail('stock.quantity', 'остаток не конечное число', $key);
            }
            if ($products !== [] && ! isset($products[$b->productId])) {
                $this->fail('stock.products', 'productId остатка нет в fetchProducts()', $key);
            }
            if ($warehouses !== [] && ! isset($warehouses[$b->warehouseId])) {
                $this->fail('stock.warehouses', 'warehouseId остатка нет в fetchWarehouses()', $key);
            }
            $result[$key] = ($result[$key] ?? 0.0) + $b->quantity;
        }

        return $result;
    }

    /**
     * @param  list<AdapterCapability>  $capabilities
     */
    private function historyBounds(ProvidesHistoryBounds&DataSourceAdapter $adapter, array $capabilities): void
    {
        $end = new DateTimeImmutable($this->day($adapter->historyEnd()));
        $after = new DateRange($end->modify('+1 day'), $end->modify('+31 days'));

        foreach (self::untrusted($adapter->fetchDeals($after)) as $deal) {
            $this->fail('history.bounds', 'сделка после historyEnd() '.$end->format('Y-m-d'), $deal instanceof Deal ? $deal->id : get_debug_type($deal));
        }
        if (in_array(AdapterCapability::StockMovements, $capabilities, true)) {
            foreach (self::untrusted($adapter->fetchStockMovements($after)) as $m) {
                $this->fail('history.bounds', 'движение после historyEnd() '.$end->format('Y-m-d'), $m instanceof StockMovement ? $m->id : get_debug_type($m));
            }
        }
    }

    /**
     * Включительность границ: записи диапазона = записи [start..D] ∪
     * [D+1..end], и записи дня D = выборке за [D..D]. D — день записи из
     * середины диапазона (не последний день): на разрезе должны быть данные,
     * иначе ошибку «конец не включительно» не увидеть.
     *
     * @template T of Deal|StockMovement
     *
     * @param  array<array-key, string>  $whole  id => отпечаток из первой выборки
     * @param  array<array-key, string>  $days  id => Y-m-d
     * @param  callable(DateRange): iterable<mixed>  $fetch
     * @param  class-string<T>  $class
     * @param  callable(T): string  $print
     */
    private function boundaries(string $rule, DateRange $range, array $whole, array $days, callable $fetch, string $class, callable $print): void
    {
        $start = $this->startDay($range);
        $end = new DateTimeImmutable($this->day($range->end));
        $candidates = array_values(array_unique(array_filter(
            $days,
            fn (string $d) => $d >= $start->format('Y-m-d') && $d < $end->format('Y-m-d'),
        )));
        sort($candidates);
        if ($candidates === []) {
            $this->skipped[] = "{$rule}: нет записей раньше последнего дня диапазона";

            return;
        }

        $cut = $candidates[intdiv(count($candidates), 2)];
        $cutDay = new DateTimeImmutable($cut);
        $message = "выборка не сходится при разрезе диапазона по {$cut} (границы должны быть включительно)";

        $union = [];
        foreach ([new DateRange($start, $cutDay), new DateRange($cutDay->modify('+1 day'), $end)] as $part) {
            foreach ($this->prints($fetch($part), $class, $print) as $id => $p) {
                if (isset($union[$id])) {
                    $this->fail($rule, $message, "{$id}: в обеих частях");
                }
                $union[$id] = $p;
            }
        }
        $this->sameSet($rule, $message, $whole, $union);

        $ofDay = array_intersect_key($whole, array_filter($days, fn (string $d) => $d === $cut));
        $this->sameSet($rule, $message, $ofDay, $this->prints($fetch(new DateRange($cutDay, $cutDay)), $class, $print));
    }

    /**
     * @template T of Deal|StockMovement
     *
     * @param  iterable<mixed>  $items
     * @param  class-string<T>  $class  записи другого типа пропускаются (о них сообщает первая выборка)
     * @param  callable(T): string  $print
     * @return array<array-key, string> id => отпечаток
     */
    private function prints(iterable $items, string $class, callable $print): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof $class) {
                $result[$item->id] = $print($item);
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, string>  $expected
     * @param  array<array-key, string>  $actual
     */
    private function sameSet(string $rule, string $message, array $expected, array $actual): void
    {
        foreach ($expected as $id => $p) {
            if (! isset($actual[$id])) {
                $this->fail($rule, $message, "{$id}: пропала");
            } elseif ($actual[$id] !== $p) {
                $this->fail($rule, $message, "{$id}: изменилась");
            }
        }
        foreach (array_diff_key($actual, $expected) as $id => $_) {
            $this->fail($rule, $message, "{$id}: лишняя");
        }
    }

    /**
     * Сравнение остатков по парам; отсутствующая пара = 0.
     *
     * @param  array<string, float>  $expected
     * @param  array<string, float>  $actual
     */
    private function sameBalances(string $rule, string $message, array $expected, array $actual): void
    {
        foreach (array_keys($expected + $actual) as $key) {
            $e = $expected[$key] ?? 0.0;
            $a = $actual[$key] ?? 0.0;
            if (abs($e - $a) > self::EPSILON) {
                $this->fail($rule, $message, "{$key}: ожидалось {$e}, получено {$a}");
            }
        }
    }

    private function startDay(DateRange $range): DateTimeImmutable
    {
        return new DateTimeImmutable($this->day($range->start));
    }

    private function dealPrint(Deal $d): string
    {
        return sprintf('%s|%.6F|%s|%s', $d->productId, $d->amount, $d->date->format('Y-m-d H:i:s'), $d->sellerId ?? '-');
    }

    private function movementPrint(StockMovement $m): string
    {
        return sprintf('%s|%s|%.6F|%s|%s', $m->productId, $m->warehouseId, $m->quantity, $m->type->value, $m->date->format('Y-m-d H:i:s'));
    }

    /**
     * Выдача адаптера — недоверенные данные: её тип здесь проверяется, а не
     * берётся из PHPDoc контракта.
     *
     * @param  iterable<mixed>  $items
     * @return iterable<mixed>
     */
    private static function untrusted(iterable $items): iterable
    {
        return $items;
    }

    private static function untrustedValue(mixed $value): mixed
    {
        return $value;
    }

    private function day(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Исключение из метода адаптера — тоже нарушение ("<раздел>.error"),
     * проверка остальных разделов продолжается.
     *
     * @template T
     *
     * @param  callable(): T  $section
     * @return T|null
     */
    private function guard(string $name, callable $section): mixed
    {
        try {
            return $section();
        } catch (Throwable $e) {
            $this->fail("{$name}.error", 'метод адаптера бросил исключение', get_class($e).': '.$e->getMessage());

            return null;
        }
    }

    private function fail(string $rule, string $message, ?string $example = null): void
    {
        $this->found[$rule] ??= ['message' => $message, 'count' => 0, 'examples' => []];
        $this->found[$rule]['count']++;
        if ($example !== null && count($this->found[$rule]['examples']) < self::MAX_EXAMPLES) {
            $this->found[$rule]['examples'][] = $example;
        }
    }
}
