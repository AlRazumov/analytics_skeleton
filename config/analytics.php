<?php

use App\Core\Analytics\Sellers\AvgCheck;
use App\Core\Analytics\Sellers\SalesAmount;
use App\Core\Analytics\Sellers\SalesCount;
use App\Core\Analytics\Sellers\SalesPerActiveDay;
use App\Core\Analytics\Sellers\ShareOfTotal;
use App\Core\Analytics\Sellers\Trend;
use App\Support\EnvNumber;

// Числовые пороги ниже переопределяются в .env (EnvNumber: не задано или
// пусто — значение по умолчанию; не число — ошибка при загрузке конфига).
// Список всех переменных — docs/features.md. Пороги расчёта (stock,
// lost_sales) меняют метрики только после следующего metrics:calculate.

// Порог неликвида в днях: и для расчёта метрики, и (по умолчанию) для показа.
$deadStockDays = EnvNumber::int('ANALYTICS_DEAD_STOCK_DAYS', 90, 1);

return [
    // Источник данных: в этапах 15/16 добавятся реальные адаптеры; пока только 'mock'.
    'source' => env('ANALYTICS_SOURCE', 'mock'),

    'mock' => [
        'profile' => env('ANALYTICS_MOCK_PROFILE', 'medium'),
        'seed' => (int) env('ANALYTICS_MOCK_SEED', 42),
        // Сколько сделок несёт продавца: full | partial | none.
        'seller_coverage' => env('ANALYTICS_MOCK_SELLER_COVERAGE', 'full'),
    ],

    // Регулярный пересчёт (routes/console.php, нужен cron с schedule:run):
    // время запуска metrics:calculate ежедневно, 'HH:MM' в часовом поясе
    // приложения; пустая строка — задача не планируется.
    'schedule' => [
        'metrics_at' => env('ANALYTICS_METRICS_AT', '03:00'),
    ],

    // Минимальный memory_limit для metrics:calculate: команда поднимает лимит
    // до этого значения (и никогда не опускает). Пик: medium — больше
    // стандартных 128M, large — ~520 МБ (docs/reports/stage-30-consistent-mock.md).
    'calculate_memory_limit' => env('ANALYTICS_CALCULATE_MEMORY_LIMIT', '1G'),

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
        'horizon_months' => EnvNumber::int('ANALYTICS_LOST_SALES_HORIZON_MONTHS', 1, 1),
    ],

    'stock' => [
        // Неликвид: порог в днях без продаж (отбор — запросом value >= порога).
        'dead_stock_days' => $deadStockDays,

        // Дни до обнуления: окно спроса в днях, заканчивающееся на asOf.
        'days_of_stock_window' => EnvNumber::int('ANALYTICS_DAYS_OF_STOCK_WINDOW', 28, 1),

        // Минимум дней с положительным остатком в окне, иначе метрика не пишется.
        'min_in_stock_days' => EnvNumber::int('ANALYTICS_MIN_IN_STOCK_DAYS', 7, 1),
    ],

    // Рекомендации перемещений между складами (дни покрытия = остаток / скорость продаж).
    // Требуется deficit_days < target_days <= keep_days <= surplus_days.
    'transfers' => [
        // Дефицит: покрытие <= порога.
        'deficit_days' => EnvNumber::int('ANALYTICS_TRANSFER_DEFICIT_DAYS', 14, 1),
        // Получателю довозят до этого покрытия.
        'target_days' => EnvNumber::int('ANALYTICS_TRANSFER_TARGET_DAYS', 30, 1),
        // Донор не опускается ниже этого покрытия.
        'keep_days' => EnvNumber::int('ANALYTICS_TRANSFER_KEEP_DAYS', 30, 1),
        // Донор: покрытие >= порога.
        'surplus_days' => EnvNumber::int('ANALYTICS_TRANSFER_SURPLUS_DAYS', 60, 1),
        // Строки меньше этого количества (шт.) не рекомендуются.
        'min_quantity' => EnvNumber::int('ANALYTICS_TRANSFER_MIN_QUANTITY', 1, 1),

        // ЭВРИСТИКА ДЛЯ ДЕМО (см. Known issues в docs/roadmap.md, «склад без
        // продаж не считается донором»): склад без единой продажи (для него
        // не считается days_of_stock) становится донором «по остатку», если
        // остаток выше этого порога; раздаётся остаток минус порог (тот же
        // смысл, что keep_days для обычного донора, но в штуках, а не днях,
        // — скорости продаж у такого склада нет). Финальное решение — при
        // появлении реального клиента.
        'stock_surplus_min_stock' => EnvNumber::int('ANALYTICS_TRANSFER_STOCK_SURPLUS_MIN_STOCK', 20),
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
        'categories' => (bool) env('ANALYTICS_FEATURE_CATEGORIES', true),
        'product_card' => (bool) env('ANALYTICS_FEATURE_PRODUCT_CARD', true),
    ],

    // Пороги и размеры таблиц на страницах (не путать с порогами расчёта).
    'display' => [
        // Неликвид на странице: days_since_last_sale >= порога.
        'dead_stock_display_days' => EnvNumber::int('ANALYTICS_DISPLAY_DEAD_STOCK_DAYS', $deadStockDays, 1),

        // Риск дефицита на странице: days_of_stock <= порога.
        'stockout_risk_days' => EnvNumber::int('ANALYTICS_DISPLAY_STOCKOUT_RISK_DAYS', 14),

        // Максимум строк в таблице (для топа и анти-топа — каждой), 1..1000.
        'table_limit' => EnvNumber::int('ANALYTICS_DISPLAY_TABLE_LIMIT', 20, 1, 1000),

        // Границы корзин графиков (нижняя граница каждой следующей корзины,
        // по возрастанию). Первая корзина каждого графика задаётся сама:
        // неликвиды — от dead_stock_display_days, дни до обнуления — от 0,
        // оборачиваемость — точный 0, затем (0; первая граница).
        // В .env — через запятую: ANALYTICS_DISPLAY_TURNOVER_BOUNDS=0.5,1,2
        'dead_stock_age_bounds' => EnvNumber::intList('ANALYTICS_DISPLAY_DEAD_STOCK_AGE_BOUNDS', [180, 365], 1),
        'days_of_stock_bounds' => EnvNumber::intList('ANALYTICS_DISPLAY_DAYS_OF_STOCK_BOUNDS', [8, 15, 31, 61], 1),
        'turnover_bounds' => EnvNumber::floatList('ANALYTICS_DISPLAY_TURNOVER_BOUNDS', [1.0, 2.0]),

        // Карточка товара: длина помесячной динамики (включая выбранный месяц).
        'product_history_months' => EnvNumber::int('ANALYTICS_DISPLAY_PRODUCT_HISTORY_MONTHS', 12, 1),

        // Индикатор свежести данных: часовой пояс времени расчёта на страницах
        // (приложение и планировщик — в UTC) и через сколько часов после
        // последнего успешного расчёта данные помечаются устаревшими
        // (ночной расчёт + запас).
        'timezone' => env('ANALYTICS_DISPLAY_TIMEZONE', 'Europe/Moscow'),
        'stale_after_hours' => EnvNumber::int('ANALYTICS_STALE_AFTER_HOURS', 36, 1),
    ],
];
