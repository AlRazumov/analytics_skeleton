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
     * Ключ `period` снэпшота, рассчитанного последним по факту (по
     * моменту записи в БД — `created_at`, а не по значению `period`),
     * для (entityType, metricKey), или null, если снэпшотов ещё нет.
     *
     * Единственный источник знания о том, как искать "актуальный"
     * снэпшот для метрик, которые не образуют помесячную серию, а
     * хранят один срез на текущий момент (например,
     * abc_xyz_classification — см. AbcClassifier/XyzClassifier: их
     * period — это последний месяц диапазона, переданного в
     * MetricsCalculationService::calculate(), а не помесячная запись).
     * Consumer'ам не нужно (и не должно быть нужно) знать/угадывать
     * этот period самостоятельно — раньше это дублировалось в каждом
     * контроллере и расходилось с реальным периодом прогона
     * metrics:calculate.
     *
     * Важно: "самый свежий" — это снэпшот с наибольшим `created_at`
     * (моментом записи), а НЕ снэпшот с лексикографически/численно
     * наибольшим `period`. При бэкфилле старого периода поверх уже
     * посчитанного более позднего period это два разных снэпшота —
     * см. отчёт по бэкфилл-багу в docs/reports.
     */
    public function latestPeriodFor(string $entityType, string $metricKey): ?string;
}
