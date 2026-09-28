<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Снэпшоты одного товара — для карточки товара. Реализация живёт вне core.
 */
interface ProductMetricsRepository
{
    /**
     * Строки метрики товара (entity_type 'product') за периоды $periodKeys.
     *
     * @param  string[]  $periodKeys
     * @return array<string, MetricsSnapshotRecord> ключ периода => строка
     */
    public function forProduct(string $metricKey, string $productId, array $periodKeys): array;

    /**
     * Строки метрики пар товар × склад (entity_type 'product_warehouse') этого
     * товара за период, по entity_id по возрастанию (побайтово).
     *
     * @return list<MetricsSnapshotRecord>
     */
    public function forProductWarehouses(string $metricKey, string $productId, string $periodKey): array;
}
