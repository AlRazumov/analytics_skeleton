<?php

namespace App\Core\Analytics;

use App\Core\Analytics\Sellers\SellerMetricsCalculator;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Прогоняет все калькуляторы (`core/Analytics/*Calculator`,
 * `*Classifier`) по одному адаптеру и периоду, возвращая готовый набор
 * MetricsSnapshotRecord для записи через MetricsSnapshotWriter.
 *
 * Не знает о точке входа (artisan-команда, Job, ...) — принимает
 * только контракт DataSourceAdapter, поэтому вызывается откуда угодно
 * без изменений (см. отчёт stage-04, требование "логика отделена от
 * команды").
 *
 * Обращения к адаптеру. `fetchDeals()` вызывается здесь ровно один раз
 * за calculate() и отдаётся revenue/abc/xyz готовым аргументом (раньше
 * вызывался трижды — см. docs/roadmap.md и docs/reports/stage-04-report.md).
 * Для оборачиваемости здесь же один `fetchStockMovements()` и один
 * `fetchStock(начало диапазона − 1 день)` (стартовый остаток), результат
 * передаётся калькулятору аргументами. Метрики остатков
 * (DeadStockCalculator, DaysOfStockCalculator) принимают сам адаптер и
 * читают его сами: неликвидам нужны остатки и окно движений с lookback
 * (по одному fetchStock() и fetchStockMovements()), дням до обнуления —
 * по одному fetchStock() + fetchStockMovements() на каждый месяц
 * диапазона. Итого за calculate() при обоих capabilities:
 * fetchStock() и fetchStockMovements() — по (2 + число месяцев) раз.
 * Все три метрики остатков считаются, только если у адаптера есть
 * StockMovements и StockSnapshots (иначе причина — warning в лог, без
 * исключения; turnover без остатка не считается, а не считается неверно).
 *
 * AbcClassifier и XyzClassifier независимо считают свою часть
 * классификации и каждый пишет только свою часть value_meta
 * (abc_class / xyz_class) в один и тот же metric_key
 * ('abc_xyz_classification'). Эта схема сливает их построчно в одну
 * запись — в metrics_snapshots должна быть одна строка на товар для
 * этой метрики, а не две конкурирующие. Слияние матчит записи по
 * entityId и полагается на инвариант "period у обоих классификаторов
 * одинаковый" — при его нарушении бросает исключение, а не пишет
 * неверный period молча (см. mergeAbcXyz()).
 */
final class MetricsCalculationService
{
    public function __construct(
        private readonly RevenueByPeriodCalculator $revenue = new RevenueByPeriodCalculator,
        private readonly AbcClassifier $abc = new AbcClassifier,
        private readonly XyzClassifier $xyz = new XyzClassifier,
        private readonly TurnoverCalculator $turnover = new TurnoverCalculator,
        private readonly DeadStockCalculator $deadStock = new DeadStockCalculator,
        private readonly DaysOfStockCalculator $daysOfStock = new DaysOfStockCalculator,
        private readonly LostSalesCalculator $lostSales = new LostSalesCalculator,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly ?SellerMetricsCalculator $sellers = null,
    ) {}

    /**
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array
    {
        // fetchDeals() отдаётся revenue/abc/xyz-калькуляторам одним и
        // тем же значением — если адаптер вернёт Generator (как
        // MockAdapter), он допускает только однократный обход, поэтому
        // материализуем в массив сразу после единственного вызова.
        $rawDeals = $adapter->fetchDeals($period);
        $deals = is_array($rawDeals) ? $rawDeals : iterator_to_array($rawDeals);
        $capabilities = $adapter->capabilities();
        $hasMovements = in_array(AdapterCapability::StockMovements, $capabilities, true);
        $hasSnapshots = in_array(AdapterCapability::StockSnapshots, $capabilities, true);

        // Источник без нужных возможностей не ломает прогон: метрика не
        // считается, причина уходит в лог. Оборачиваемости нужен реальный
        // остаток на начало диапазона (StockSnapshots) — считать без него
        // значит писать заведомо неверные числа.
        $canTurnover = $hasMovements && $hasSnapshots;
        if (! $canTurnover) {
            $this->logger->warning(sprintf(
                'Метрика turnover не считается: нужны capabilities StockMovements и StockSnapshots, есть %s.',
                implode(', ', array_map(fn ($c) => $c->value, $capabilities)) ?: 'ни одной',
            ));
        }

        $turnoverRecords = [];
        if ($canTurnover) {
            $openingStock = [];
            $openingDate = (new DateTimeImmutable($period->start->format('Y-m-d')))->modify('-1 day');
            foreach ($adapter->fetchStock($openingDate) as $balance) {
                $openingStock[$balance->productId] = ($openingStock[$balance->productId] ?? 0.0) + $balance->quantity;
            }
            $turnoverRecords = $this->turnover->calculate($adapter->fetchStockMovements($period), $period, $openingStock);
        }

        $records = [
            ...$this->revenue->calculate($deals, $period),
            ...$this->mergeAbcXyz(
                $this->abc->calculate($deals, $period),
                $this->xyz->calculate($deals, $period),
            ),
            ...$turnoverRecords,
            // Продавцы: те же deals, один вызов fetchDeals(); реестр — из конфига через контейнер.
            ...($this->sellers ?? new SellerMetricsCalculator(SellerMetricsCalculator::builtIn()))
                ->calculate($deals, $adapter->sellerCoverage()),
            // Потерянные продажи: та же выборка deals, эвристика для демо (см. докблок LostSalesCalculator).
            ...$this->lostSales->calculate($deals, $period),
        ];

        // Метрики остатков читают окна сами (свои вызовы fetchStock /
        // fetchStockMovements), им нужны обе возможности.
        if ($hasMovements && $hasSnapshots) {
            array_push($records, ...$this->deadStock->calculate($adapter, $period));
            array_push($records, ...$this->daysOfStock->calculate($adapter, $period));
            $skipped = $this->daysOfStock->lastSkipped;
            $this->logger->info('days_of_stock: пропущено пар товар×склад', $skipped);
        } else {
            $this->logger->warning(sprintf(
                'Метрики %s и %s не считаются: нужны capabilities StockMovements и StockSnapshots, есть %s.',
                DeadStockCalculator::METRIC_KEY,
                DaysOfStockCalculator::METRIC_KEY,
                implode(', ', array_map(fn ($c) => $c->value, $capabilities)) ?: 'ни одной',
            ));
        }

        return $records;
    }

    /**
     * ИНВАРИАНТ: матчинг идёт только по entityId, period НЕ участвует
     * в ключе слияния — это безопасно ровно потому, что оба
     * классификатора всегда кладут period = последний месяц
     * переданного периода (см. их докблоки), то есть для одного и
     * того же вызова calculate() period у обоих всегда совпадает.
     * Нарушение инварианта не проглатывается молча: если для одного
     * entityId у ABC- и XYZ-записи разный period, бросается
     * RuntimeException (см. ниже) — иначе period и value итоговой
     * записи молча взялись бы от AbcClassifier, а расхождение осталось
     * бы незамеченным. При изменении period-семантики любого из
     * классификаторов (например, если один станет считать по
     * кварталам) это исключение сразу укажет, что merge требует
     * пересмотра, вместо того чтобы тихо писать неверный period.
     *
     * @param  MetricsSnapshotRecord[]  $abcRecords
     * @param  MetricsSnapshotRecord[]  $xyzRecords
     * @return MetricsSnapshotRecord[]
     *
     * @throws RuntimeException если у ABC- и XYZ-записи одного entityId разный period
     */
    private function mergeAbcXyz(array $abcRecords, array $xyzRecords): array
    {
        /** @var array<string, MetricsSnapshotRecord> $byEntityId */
        $byEntityId = [];

        foreach ($abcRecords as $record) {
            $byEntityId[$record->entityId] = $record;
        }

        foreach ($xyzRecords as $record) {
            $existing = $byEntityId[$record->entityId] ?? null;

            if ($existing !== null && $existing->period !== $record->period) {
                throw new RuntimeException(sprintf(
                    "MetricsCalculationService::mergeAbcXyz: расхождение period для entityId='%s' — AbcClassifier дал '%s', XyzClassifier дал '%s'. ".
                    'Инвариант "оба классификатора кладут одинаковый period" нарушен — merge по entityId больше не безопасен, нужен пересмотр логики слияния.',
                    $record->entityId,
                    $existing->period,
                    $record->period,
                ));
            }

            $byEntityId[$record->entityId] = new MetricsSnapshotRecord(
                entityType: $record->entityType,
                entityId: $record->entityId,
                metricKey: $record->metricKey,
                value: $existing->value ?? $record->value,
                period: $existing->period ?? $record->period,
                valueMeta: [
                    ...($existing->valueMeta ?? []),
                    ...$record->valueMeta,
                ],
            );
        }

        return array_values($byEntityId);
    }
}
