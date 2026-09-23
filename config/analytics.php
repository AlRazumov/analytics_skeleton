<?php

use App\Core\Analytics\Sellers\AvgCheck;
use App\Core\Analytics\Sellers\SalesAmount;
use App\Core\Analytics\Sellers\SalesCount;
use App\Core\Analytics\Sellers\SalesPerActiveDay;
use App\Core\Analytics\Sellers\ShareOfTotal;
use App\Core\Analytics\Sellers\Trend;

// Порог неликвида в днях: и для расчёта метрики, и (по умолчанию) для показа.
$deadStockDays = 90;

return [
    // Источник данных: в этапах 15/16 добавятся реальные адаптеры; пока только 'mock'.
    'source' => env('ANALYTICS_SOURCE', 'mock'),

    'mock' => [
        'profile' => env('ANALYTICS_MOCK_PROFILE', 'medium'),
        'seed' => (int) env('ANALYTICS_MOCK_SEED', 42),
        // Сколько сделок несёт продавца: full | partial | none.
        'seller_coverage' => env('ANALYTICS_MOCK_SELLER_COVERAGE', 'full'),
    ],

    // Реестр метрик по типам сущностей: все известные и включённые
    // (считаются и показываются только включённые). Ключ — metric_key.
    'metrics' => [
        'seller' => [
            SalesCount::class,
            SalesAmount::class,
            AvgCheck::class,
            ShareOfTotal::class,
            SalesPerActiveDay::class,
            Trend::class,
        ],
    ],
    'enabled_metrics' => [
        'seller' => ['sales_count', 'sales_amount', 'avg_check', 'share_of_total', 'sales_per_active_day', 'trend'],
    ],

    // Колонки обобщённого топ-N (<x-widgets.top-n>) по типам сущностей.
    'top_n' => [
        'seller' => ['columns' => ['sales_count', 'sales_amount', 'share_of_total']],
    ],

    // Потерянные продажи (эвристика для демо, см. LostSalesCalculator и
    // Known issues в docs/roadmap.md): сколько месяцев "было" сравнивать
    // с текущим, чтобы считать падение продаж до нуля потерей.
    'lost_sales' => [
        'horizon_months' => 1,
    ],

    'stock' => [
        // Неликвид: порог в днях без продаж (отбор — запросом value >= порога).
        'dead_stock_days' => $deadStockDays,

        // Дни до обнуления: окно спроса в днях, заканчивающееся на asOf.
        'days_of_stock_window' => 28,

        // Минимум дней с положительным остатком в окне, иначе метрика не пишется.
        'min_in_stock_days' => 7,
    ],

    // Рекомендации перемещений между складами (дни покрытия = остаток / скорость продаж).
    // Требуется deficit_days < target_days <= keep_days <= surplus_days.
    'transfers' => [
        // Дефицит: покрытие <= порога.
        'deficit_days' => 14,
        // Получателю довозят до этого покрытия.
        'target_days' => 30,
        // Донор не опускается ниже этого покрытия.
        'keep_days' => 30,
        // Донор: покрытие >= порога.
        'surplus_days' => 60,
        // Строки меньше этого количества (шт.) не рекомендуются.
        'min_quantity' => 1,

        // ЭВРИСТИКА ДЛЯ ДЕМО (см. Known issues в docs/roadmap.md, «склад без
        // продаж не считается донором»): склад без единой продажи (для него
        // не считается days_of_stock) становится донором «по остатку», если
        // остаток выше этого порога; раздаётся остаток минус порог (тот же
        // смысл, что keep_days для обычного донора, но в штуках, а не днях,
        // — скорости продаж у такого склада нет). Финальное решение — при
        // появлении реального клиента.
        'stock_surplus_min_stock' => 20,
    ],

    // Флаги функциональности влияют ТОЛЬКО на показ (роуты, навигация,
    // виджеты). Метрики считаются всегда, независимо от флагов.
    'features' => [
        'dead_stock' => (bool) env('ANALYTICS_FEATURE_DEAD_STOCK', true),
        'stockout_risk' => (bool) env('ANALYTICS_FEATURE_STOCKOUT_RISK', true),
        'top_products' => (bool) env('ANALYTICS_FEATURE_TOP_PRODUCTS', true),
        'turnover' => (bool) env('ANALYTICS_FEATURE_TURNOVER', true),
        'transfers' => (bool) env('ANALYTICS_FEATURE_TRANSFERS', true),
        'sellers' => (bool) env('ANALYTICS_FEATURE_SELLERS', true),
    ],

    // Пороги и размеры таблиц на страницах (не путать с порогами расчёта).
    'display' => [
        // Неликвид на странице: days_since_last_sale >= порога.
        'dead_stock_display_days' => $deadStockDays,

        // Риск дефицита на странице: days_of_stock <= порога.
        'stockout_risk_days' => 14,

        // Максимум строк в таблице (для топа и анти-топа — каждой).
        'table_limit' => 20,

        // Границы корзин графиков (нижняя граница каждой следующей корзины,
        // по возрастанию). Первая корзина каждого графика задаётся сама:
        // неликвиды — от dead_stock_display_days, дни до обнуления — от 0,
        // оборачиваемость — точный 0, затем (0; первая граница).
        'dead_stock_age_bounds' => [180, 365],
        'days_of_stock_bounds' => [8, 15, 31, 61],
        'turnover_bounds' => [1.0, 2.0],
    ],
];
