<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staging_sellers', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('branch_external_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('meta')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        // Как product_external_id: связь по внешнему id без FK; null — у сделки нет продавца.
        Schema::table('staging_deals', function (Blueprint $table) {
            $table->string('seller_external_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('staging_deals', function (Blueprint $table) {
            $table->dropColumn('seller_external_id');
        });
        Schema::dropIfExists('staging_sellers');
    }
};
