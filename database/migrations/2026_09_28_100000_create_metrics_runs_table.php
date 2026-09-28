<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал запусков metrics:calculate — для индикатора свежести данных.
        Schema::create('metrics_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('status', 16)->index(); // running | success | failed
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('snapshots_written')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_runs');
    }
};
