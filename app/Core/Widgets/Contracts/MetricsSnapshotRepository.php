<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Единственная точка чтения агрегированных метрик для слоя widgets.
 * Реализация (Eloquent/`metrics_snapshots`) живёт вне core — core знает
 * только об этом контракте.
 */
interface MetricsSnapshotRepository
{
    /**
     * @param  string[]  $periodKeys  ключи `metrics_snapshots.period`
     * @return MetricsSnapshotRecord[]
     */
    public function findByPeriodKeys(string $entityType, string $metricKey, array $periodKeys): array;

    /**
     * Ключ `period` самого свежего снэпшота для (entityType, metricKey) —
     * с наибольшим `period_start` (при равенстве — с наибольшим `id`); момент
     * записи `created_at` не учитывается. Null, если снэпшотов ещё нет.
     *
     * Единственный источник знания о том, как искать "актуальный"
     * снэпшот для метрик, которые не образуют помесячную серию, а
     * хранят один срез на текущий момент (например,
     * abc_xyz_classification — см. AbcClassifier/XyzClassifier: их
     * period — это последний месяц диапазона, переданного в
     * MetricsCalculationService::calculate(), а не помесячная запись).
     * Consumer'ам не нужно (и не должно быть нужно) знать/угадывать
     * этот period самостоятельно.
     *
     * Пересчёт/бэкфилл более раннего периода не меняет «последний период».
     */
    public function latestPeriodFor(string $entityType, string $metricKey): ?string;
}
