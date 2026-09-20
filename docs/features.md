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
