<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Строковый `period` ('YYYY-MM' / 'YYYY-MM-DD') → тройка
     * period_type / period_start / period_end (конец включительно), чтобы
     * PoP/YoY считались оконными функциями по period_start. Существующие
     * строки переносятся: 'YYYY-MM' → month, 'YYYY-MM-DD' → day; любой
     * другой формат — ошибка (молча терять данные нельзя).
     */
    public function up(): void
    {
        Schema::table('metrics_snapshots', function (Blueprint $table) {
            $table->string('period_type')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE metrics_snapshots SET
                period_type = CASE WHEN period ~ '^\d{4}-\d{2}$' THEN 'month' ELSE 'day' END,
                period_start = CASE WHEN period ~ '^\d{4}-\d{2}$'
                    THEN to_date(period || '-01', 'YYYY-MM-DD') ELSE to_date(period, 'YYYY-MM-DD') END
            WHERE period ~ '^\d{4}-\d{2}(-\d{2})?$'
        SQL);
        DB::statement(<<<'SQL'
            UPDATE metrics_snapshots SET period_end = CASE
                WHEN period_type = 'month' THEN (period_start + INTERVAL '1 month - 1 day')::date
                ELSE period_start END
            WHERE period_type IS NOT NULL
        SQL);

        if (DB::table('metrics_snapshots')->whereNull('period_type')->exists()) {
            throw new RuntimeException('metrics_snapshots.period содержит значения, не похожие на YYYY-MM / YYYY-MM-DD: миграция прервана.');
        }

        Schema::table('metrics_snapshots', function (Blueprint $table) {
            $table->dropIndex('metrics_snapshots_entity_metric_period_index');
            $table->dropColumn('period');
        });

        DB::statement('ALTER TABLE metrics_snapshots ALTER COLUMN period_type SET NOT NULL');
        DB::statement('ALTER TABLE metrics_snapshots ALTER COLUMN period_start SET NOT NULL');
        DB::statement('ALTER TABLE metrics_snapshots ALTER COLUMN period_end SET NOT NULL');

        Schema::table('metrics_snapshots', function (Blueprint $table) {
            $table->unique(
                ['entity_type', 'entity_id', 'metric_key', 'period_type', 'period_start'],
                'metrics_snapshots_entity_metric_period_unique'
            );
            // Под выборку топ/анти-топ по значению внутри периода.
            $table->index(
                ['metric_key', 'period_type', 'period_start', 'value'],
                'metrics_snapshots_metric_period_value_index'
            );
        });
    }

    /**
     * Обратно: day/month → прежние строки; week/quarter/year (в старой
     * схеме не существовали) сохраняются каноническим ключом, например
     * 'quarter:2026-Q3', чтобы rollback не терял строки.
     */
    public function down(): void
    {
        Schema::table('metrics_snapshots', function (Blueprint $table) {
            $table->dropUnique('metrics_snapshots_entity_metric_period_unique');
            $table->dropIndex('metrics_snapshots_metric_period_value_index');
            $table->string('period')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE metrics_snapshots SET period = CASE period_type
                WHEN 'month' THEN to_char(period_start, 'YYYY-MM')
                WHEN 'day' THEN to_char(period_start, 'YYYY-MM-DD')
                WHEN 'week' THEN 'week:' || to_char(period_start, 'IYYY-"W"IW')
                WHEN 'quarter' THEN 'quarter:' || to_char(period_start, 'YYYY-"Q"Q')
                ELSE 'year:' || to_char(period_start, 'YYYY') END
        SQL);
        DB::statement('ALTER TABLE metrics_snapshots ALTER COLUMN period SET NOT NULL');

        Schema::table('metrics_snapshots', function (Blueprint $table) {
            $table->dropColumn(['period_type', 'period_start', 'period_end']);
            $table->index(
                ['entity_type', 'entity_id', 'metric_key', 'period'],
                'metrics_snapshots_entity_metric_period_index'
            );
        });
    }
};
