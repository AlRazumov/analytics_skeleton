<?php

namespace App\Providers;

use App\Adapters\DataSourceAdapterFactory;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotRepository;
use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Core\Widgets\Contracts\ProductNameResolver;
use App\Core\Widgets\Contracts\WarehouseNameResolver;
use App\Repositories\DbProductNameResolver;
use App\Repositories\DbWarehouseNameResolver;
use App\Repositories\EloquentMetricsComparisonRepository;
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
        $this->app->bind(MetricsComparisonRepository::class, EloquentMetricsComparisonRepository::class);

        // Источник данных выбирается конфигом (analytics.source).
        $this->app->bind(DataSourceAdapter::class, fn ($app) => $app->make(DataSourceAdapterFactory::class)->make());

        // Названия для страниц — из справочников в БД (наполняются reference:sync).
        $this->app->bind(ProductNameResolver::class, DbProductNameResolver::class);
        $this->app->bind(WarehouseNameResolver::class, DbWarehouseNameResolver::class);

        // Пороги метрик остатков живут в config/analytics.php, а core
        // о Laravel-конфиге не знает — передаём значения конструктором.
        $this->app->bind(DeadStockCalculator::class, fn () => new DeadStockCalculator(
            (int) config('analytics.stock.dead_stock_days'),
        ));
        $this->app->bind(DaysOfStockCalculator::class, fn () => new DaysOfStockCalculator(
            (int) config('analytics.stock.days_of_stock_window'),
            (int) config('analytics.stock.min_in_stock_days'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
