<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Единственная точка записи агрегированных метрик из расчётного
 * пайплайна (core/Analytics) в хранилище. Отдельно от
 * MetricsSnapshotRepository (тот остаётся чисто read-only и
 * используется WidgetDataProvider) — чтение и запись имеют разных
 * потребителей и разное время жизни.
 */
interface MetricsSnapshotWriter
{
    /**
     * @param  MetricsSnapshotRecord[]  $records
     */
    public function write(array $records): void;
}
