# Флаги функциональности

Флаги в `config/analytics.php` (`features`) управляют только **показом**:
роутами (404 при выключенном флаге), пунктами навигации и виджетами.
Метрики считаются всегда, независимо от флагов. Пакетов не требуется;
значения задаются в `.env` (по умолчанию все включены).

| Флаг | Env | Что скрывает |
|------|-----|--------------|
| `dead_stock` | `ANALYTICS_FEATURE_DEAD_STOCK` | виджет «Неликвиды» на `/dashboards/stock` |
| `stockout_risk` | `ANALYTICS_FEATURE_STOCKOUT_RISK` | виджет «Риск дефицита» на `/dashboards/stock` |
| `top_products` | `ANALYTICS_FEATURE_TOP_PRODUCTS` | страницу `/dashboards/top-products` и пункт «Топ товаров» |
| `turnover` | `ANALYTICS_FEATURE_TURNOVER` | страницу `/dashboards/turnover` и пункт «Оборачиваемость» |
| `transfers` | `ANALYTICS_FEATURE_TRANSFERS` | страницу `/dashboards/transfers` и пункт «Перемещения» |
| `sellers` | `ANALYTICS_FEATURE_SELLERS` | блок «Топ-3 продавцов» на обзоре |

Страница `/dashboards/stock` и пункт «Остатки» скрыты (404), только если
выключены **оба** флага — `dead_stock` и `stockout_risk`.

Реализация: middleware `feature:<имя>[,<имя>...]` (`EnsureFeatureEnabled`,
404, если выключены все перечисленные флаги).

## Пороги показа (`analytics.display`)

| Ключ | По умолчанию | Смысл |
|------|--------------|-------|
| `dead_stock_display_days` | = `stock.dead_stock_days` (90) | неликвид: `days_since_last_sale >=` порога |
| `stockout_risk_days` | 14 | риск дефицита: `days_of_stock <=` порога |
| `table_limit` | 20 | максимум строк в таблице (топ и анти-топ — каждая) |
| `dead_stock_age_bounds` | `[180, 365]` | границы корзин графика «Неликвиды по возрасту» (первая корзина — от `dead_stock_display_days`) |
| `days_of_stock_bounds` | `[8, 15, 31, 61]` | границы корзин графика «Дни до обнуления» (первая — от 0) |
| `turnover_bounds` | `[1.0, 2.0]` | границы корзин распределения оборачиваемости (корзины: 0; (0; 1); [1; 2); 2+) |

## Пороги рекомендаций перемещений (`analytics.transfers`)

Покрытие = остаток / средние продажи в день (по метрике `days_of_stock`).
Требуется `deficit_days < target_days <= keep_days <= surplus_days`,
`min_quantity > 0` (иначе планировщик бросает `InvalidArgumentException`).

| Ключ | По умолчанию | Смысл |
|------|--------------|-------|
| `deficit_days` | 14 | дефицит: покрытие `<=` порога |
| `target_days` | 30 | получателю довозят до этого покрытия |
| `keep_days` | 30 | донор не опускается ниже этого покрытия |
| `surplus_days` | 60 | донор: покрытие `>=` порога |
| `min_quantity` | 1 | строки меньше (шт.) отбрасываются |

Число строк на странице ограничивает `analytics.display.table_limit`.
