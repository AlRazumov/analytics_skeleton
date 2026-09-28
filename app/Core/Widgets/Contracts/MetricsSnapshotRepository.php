<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Widgets\DTO\MatrixCellData;
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
     * Сумма value по всем сущностям для каждого периода (агрегация в
     * хранилище, строки не читаются). Периодов без строк в ответе нет.
     *
     * @param  string[]  $periodKeys
     * @return array<string, float> ключ периода => сумма
     */
    public function sumsByPeriod(string $entityType, string $metricKey, array $periodKeys): array;

    /**
     * Первые $limit сущностей по сумме value за периоды $periodKeys (по
     * убыванию суммы, ничьи — по entity_id по возрастанию) со значениями по
     * периодам, и общее число сущностей со строками в этих периодах.
     * Сортировка и LIMIT — в хранилище.
     *
     * @param  string[]  $periodKeys
     * @return array{rows: list<array{entityId: string, byPeriod: array<string, float>}>, total: int}
     */
    public function topBySum(string $entityType, string $metricKey, array $periodKeys, int $limit): array;

    /**
     * Ячейки «число сущностей и сумма value» за период, сгруппированные по
     * двум ключам value_meta (для отсутствующего ключа — '?'). Группировка —
     * в хранилище. $productCategory — только товары этой категории
     * справочника (как в MetricsComparisonRepository; NO_CATEGORY — без
     * категории), только для entity_type product/product_warehouse.
     *
     * @return list<MatrixCellData>
     */
    public function cellsByMeta(string $entityType, string $metricKey, string $periodKey, string $rowMetaKey, string $colMetaKey, ?string $productCategory = null): array;

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
