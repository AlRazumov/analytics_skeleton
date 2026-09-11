<?php

namespace App\Core\Analytics;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
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
    ) {}

    /**
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array
    {
        $records = [
            ...$this->revenue->calculate($adapter, $period),
            ...$this->mergeAbcXyz(
                $this->abc->calculate($adapter, $period),
                $this->xyz->calculate($adapter, $period),
            ),
            ...$this->turnover->calculate($adapter, $period),
        ];

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
