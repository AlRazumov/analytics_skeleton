<?php

namespace App\Providers;

use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Repositories\EloquentMetricsSnapshotRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MetricsSnapshotRepository::class, EloquentMetricsSnapshotRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
