<?php

namespace App\Core\Widgets\DTO;

/**
 * Одна строка `metrics_snapshots`, прочитанная через
 * MetricsSnapshotRepository. Не Eloquent-модель — core не знает о
 * хранилище, только о форме данных.
 */
final readonly class MetricsSnapshotRecord
{
    /**
     * @param  array<string, mixed>  $valueMeta
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $metricKey,
        public float $value,
        public string $period,
        public array $valueMeta = [],
    ) {}
}
