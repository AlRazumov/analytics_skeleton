<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
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
 * Обращения к адаптеру. `fetchDeals()` и `fetchStockMovements()` для
 * revenue/abc/xyz/turnover вызываются здесь ровно по одному разу за
 * calculate(), а калькуляторы получают уже готовые данные аргументом
 * (раньше fetchDeals() вызывался трижды — см. docs/roadmap.md и
 * docs/reports/stage-04-report.md). Исключение — метрики остатков
 * (DeadStockCalculator, DaysOfStockCalculator): им нужны остатки на
 * разные даты и окна движений, поэтому они принимают сам адаптер и
 * читают его сами — за один calculate() это ещё один вызов
 * fetchStockMovements() и один fetchStock() у неликвидов (расширенное окно
 * lookback) и по одному fetchStock() + fetchStockMovements() на каждый
 * месяц диапазона у дней до обнуления. Эти метрики считаются, только если
 * у адаптера есть StockMovements и StockSnapshots (иначе причина уходит в
 * лог, без исключения).
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
        private readonly LoggerInterface $logger = new NullLogger,
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
        // считается, причина уходит в лог.
        if (! $hasMovements) {
            $this->logger->warning('Метрика turnover не считается: у адаптера нет capability StockMovements.');
        }
        $stockMovements = $hasMovements ? $adapter->fetchStockMovements($period) : [];

        $records = [
            ...$this->revenue->calculate($deals, $period),
            ...$this->mergeAbcXyz(
                $this->abc->calculate($deals, $period),
                $this->xyz->calculate($deals, $period),
            ),
            ...$this->turnover->calculate($stockMovements, $period),
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
                value: $existing?->value ?? $record->value,
                period: $existing?->period ?? $record->period,
                valueMeta: [
                    ...($existing?->valueMeta ?? []),
                    ...$record->valueMeta,
                ],
            );
        }

        return array_values($byEntityId);
    }
}
