<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staging_deals', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->index();
            $table->string('product_external_id');
            $table->decimal('amount', 20, 4);
            $table->timestamp('occurred_at');
            $table->jsonb('meta')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(
                ['product_external_id', 'occurred_at'],
                'staging_deals_product_occurred_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staging_deals');
    }
};
