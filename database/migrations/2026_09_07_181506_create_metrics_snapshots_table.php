<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Общее хранилище агрегированных метрик для всех источников
     * (1С, Bitrix24, ...).
     *
     * Формат `period` фиксируется на уровне приложения, а не в БД:
     *   - 'YYYY-MM'    — месячные агрегации
     *   - 'YYYY-MM-DD' — дневные агрегации
     * Валидация формата на этом этапе не выполняется.
     */
    public function up(): void
    {
        Schema::create('metrics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('metric_key');
            $table->decimal('value', 20, 4);
            $table->jsonb('value_meta')->nullable();
            $table->string('period');
            $table->timestamps();

            $table->index(
                ['entity_type', 'entity_id', 'metric_key', 'period'],
                'metrics_snapshots_entity_metric_period_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_snapshots');
    }
};
