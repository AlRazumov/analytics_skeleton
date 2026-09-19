<?php

namespace App\Core\Analytics;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;
use DateTimeImmutable;

/**
 * Классификация X/Y/Z по коэффициенту вариации (CV = std/mean) спроса
 * товара по месяцам периода — реальный расчёт, в отличие от
 * crc32-заглушки прежнего демо-seeder'а.
 *
 * ДОПУЩЕНИЯ (зафиксированы в отчёте stage-04):
 * - "Спрос" — сумма Deal::$amount за месяц (в DataSourceAdapter нет
 *   количества штук на сделку, только денежная сумма — заменитель
 *   объёма спроса).
 * - Для CV берутся ВСЕ месяцы периода, включая месяцы без продаж
 *   (значение 0) — иначе товар с редкими, но крупными продажами
 *   выглядел бы стабильнее, чем есть на самом деле.
 * - Используется популяционное стандартное отклонение (делитель N,
 *   не N-1) — весь период трактуется как полная генеральная
 *   совокупность наблюдений, а не выборка.
 * - Если по товару вообще нет продаж за период (mean=0), CV не
 *   определён математически — такой товар относится к Z (нестабильный
 *   спрос/его отсутствие), а не отбрасывается.
 *
 * Границы: CV<=10% -> X, CV<=25% -> Y, иначе Z.
 *
 * Один снэпшот на товар (period — последний месяц периода), value —
 * CV (доля, не проценты), value_meta — только xyz_class (abc_class
 * добавляется AbcClassifier'ом и объединяется на уровне сервиса).
 */
final class XyzClassifier
{
    private const string ENTITY_TYPE = 'product';

    private const string METRIC_KEY = 'abc_xyz_classification';

    private const float THRESHOLD_X = 0.10;

    private const float THRESHOLD_Y = 0.25;

    /**
     * @param  iterable<Deal>  $deals
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, DateRange $period): array
    {
        $months = $this->monthKeys($period);
        if ($months === []) {
            return [];
        }

        $byProductAndMonth = [];
        foreach ($deals as $deal) {
            $month = $deal->date->format('Y-m');
            $byProductAndMonth[$deal->productId][$month] = ($byProductAndMonth[$deal->productId][$month] ?? 0.0) + $deal->amount;
        }

        if ($byProductAndMonth === []) {
            return [];
        }

        $lastMonth = $period->end->format('Y-m');
        $monthCount = count($months);

        $records = [];
        foreach ($byProductAndMonth as $productId => $byMonth) {
            $values = [];
            foreach ($months as $month) {
                $values[] = $byMonth[$month] ?? 0.0;
            }

            $mean = array_sum($values) / $monthCount;

            if ($mean <= 0.0) {
                $cv = null;
                $xyzClass = 'Z';
            } else {
                $variance = array_sum(array_map(
                    static fn (float $v): float => ($v - $mean) ** 2,
                    $values,
                )) / $monthCount;
                $cv = sqrt($variance) / $mean;

                $xyzClass = match (true) {
                    $cv <= self::THRESHOLD_X => 'X',
                    $cv <= self::THRESHOLD_Y => 'Y',
                    default => 'Z',
                };
            }

            $records[] = new MetricsSnapshotRecord(
                entityType: self::ENTITY_TYPE,
                entityId: $productId,
                metricKey: self::METRIC_KEY,
                value: $cv ?? 0.0,
                period: 'month:'.$lastMonth,
                valueMeta: ['xyz_class' => $xyzClass],
            );
        }

        return $records;
    }

    /**
     * @return string[]
     */
    private function monthKeys(DateRange $period): array
    {
        $cursor = new DateTimeImmutable($period->start->format('Y-m-01'));
        $last = new DateTimeImmutable($period->end->format('Y-m-01'));

        $months = [];
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }
}
