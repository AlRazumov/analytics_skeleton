<?php

// Порог неликвида в днях: и для расчёта метрики, и (по умолчанию) для показа.
$deadStockDays = 90;

return [
    // Источник данных: в этапе 12 добавятся реальные адаптеры; пока только 'mock'.
    'source' => env('ANALYTICS_SOURCE', 'mock'),

    'mock' => [
        'profile' => env('ANALYTICS_MOCK_PROFILE', 'medium'),
        'seed' => (int) env('ANALYTICS_MOCK_SEED', 42),
    ],

    'stock' => [
        // Неликвид: порог в днях без продаж (отбор — запросом value >= порога).
        'dead_stock_days' => $deadStockDays,

        // Дни до обнуления: окно спроса в днях, заканчивающееся на asOf.
        'days_of_stock_window' => 28,

        // Минимум дней с положительным остатком в окне, иначе метрика не пишется.
        'min_in_stock_days' => 7,
    ],

    // Флаги функциональности влияют ТОЛЬКО на показ (роуты, навигация,
    // виджеты). Метрики считаются всегда, независимо от флагов.
    'features' => [
        'dead_stock' => (bool) env('ANALYTICS_FEATURE_DEAD_STOCK', true),
        'stockout_risk' => (bool) env('ANALYTICS_FEATURE_STOCKOUT_RISK', true),
        'top_products' => (bool) env('ANALYTICS_FEATURE_TOP_PRODUCTS', true),
    ],

    // Пороги и размеры таблиц на страницах (не путать с порогами расчёта).
    'display' => [
        // Неликвид на странице: days_since_last_sale >= порога.
        'dead_stock_display_days' => $deadStockDays,

        // Риск дефицита на странице: days_of_stock <= порога.
        'stockout_risk_days' => 14,

        // Максимум строк в таблице (для топа и анти-топа — каждой).
        'table_limit' => 20,
    ],
];
