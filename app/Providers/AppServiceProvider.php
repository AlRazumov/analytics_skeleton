<?php

namespace App\Providers;

use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Repositories\EloquentMetricsSnapshotRepository;
use App\Repositories\EloquentMetricsSnapshotWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MetricsSnapshotRepository::class, EloquentMetricsSnapshotRepository::class);
        $this->app->bind(MetricsSnapshotWriter::class, EloquentMetricsSnapshotWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
