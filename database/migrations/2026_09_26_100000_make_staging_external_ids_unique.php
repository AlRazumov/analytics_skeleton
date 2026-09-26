<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * external_id сделок и движений — уникальный, как у товаров, складов и
 * продавцов: будущий upsert-синк реального адаптера дедуплицирует по нему.
 * Таблицы пока ничем не заполняются, дублей в них нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['staging_deals', 'staging_stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("{$table}_external_id_index");
                $blueprint->unique('external_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['staging_deals', 'staging_stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("{$table}_external_id_unique");
                $blueprint->index('external_id');
            });
        }
    }
};
