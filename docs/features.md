# Флаги функциональности

Флаги в `config/analytics.php` (`features`) управляют только **показом**:
роутами (404 при выключенном флаге), пунктами навигации и виджетами.
Метрики считаются всегда, независимо от флагов. Пакетов не требуется;
значения задаются в `.env` (по умолчанию все включены).

| Флаг | Env | Что скрывает |
|------|-----|--------------|
| `dead_stock` | `ANALYTICS_FEATURE_DEAD_STOCK` | виджет «Неликвиды» на `/dashboards/stock` и его CSV-выгрузку |
| `stockout_risk` | `ANALYTICS_FEATURE_STOCKOUT_RISK` | виджет «Риск дефицита» на `/dashboards/stock` и его CSV-выгрузку |
| `top_products` | `ANALYTICS_FEATURE_TOP_PRODUCTS` | страницу `/dashboards/top-products` с CSV-выгрузкой и пункт «Топ товаров» |
| `turnover` | `ANALYTICS_FEATURE_TURNOVER` | страницу `/dashboards/turnover` с CSV-выгрузкой и пункт «Оборачиваемость» |
| `transfers` | `ANALYTICS_FEATURE_TRANSFERS` | страницу `/dashboards/transfers` с CSV-выгрузкой и пункт «Перемещения» |
| `sellers` | `ANALYTICS_FEATURE_SELLERS` | блок «Топ-3 продавцов» на обзоре, страницу `/dashboards/sellers` с CSV-выгрузкой и пункт «Продавцы» |
| `categories` | `ANALYTICS_FEATURE_CATEGORIES` | страницу `/dashboards/categories` с CSV-выгрузкой, пункт «Категории» и фильтр `?category=` на «Топ товаров», «Остатках», «Оборачиваемости», «ABC/XYZ» (параметр — 404) |
| `product_card` | `ANALYTICS_FEATURE_PRODUCT_CARD` | карточку товара `/dashboards/products/{id}` и ссылки на неё из названий товаров в таблицах; блоки карточки скрываются и флагами своих страниц (`turnover` — штуки и оборачиваемость, `dead_stock` — дни без продаж, `stockout_risk` — склады) |

Страница `/dashboards/stock` и пункт «Остатки» скрыты (404), только если
выключены **оба** флага — `dead_stock` и `stockout_risk`.

Реализация: middleware `feature:<имя>[,<имя>...]` (`EnsureFeatureEnabled`,
404, если выключены все перечисленные флаги).

## Настройка порогов под проект (`.env`)

Все числовые пороги из `config/analytics.php` задаются переменными `.env`
(колонка «Env» в таблицах ниже); без переменной — значение по умолчанию.
Правило разбора (`App\Support\EnvNumber`): пусто — по умолчанию; не число или
вне допустимого диапазона — ошибка при загрузке конфига с именем переменной
(приложение и `config:cache` не стартуют, а не считают молча с нулём).
Списки — через запятую: `ANALYTICS_DISPLAY_DAYS_OF_STOCK_BOUNDS=7,14,30`.

Как применить на сервере: поправить `.env`, затем `php artisan config:cache`
(при закэшированном конфиге `.env` не перечитывается). Пороги **показа** и
**перемещений** действуют сразу; пороги **расчёта** (`stock`, `lost_sales`)
меняют метрики только после следующего `metrics:calculate` (ночного или
ручного `php artisan metrics:calculate`).

## Пороги расчёта (`analytics.stock`, `analytics.lost_sales`)

| Ключ | Env | По умолчанию | Смысл |
|------|-----|--------------|-------|
| `stock.dead_stock_days` | `ANALYTICS_DEAD_STOCK_DAYS` | 90 | неликвид: дней без продаж (и порог показа по умолчанию) |
| `stock.days_of_stock_window` | `ANALYTICS_DAYS_OF_STOCK_WINDOW` | 28 | окно, по которому считается скорость продаж для дней до обнуления, дней |
| `stock.min_in_stock_days` | `ANALYTICS_MIN_IN_STOCK_DAYS` | 7 | минимум дней с остатком в окне, иначе дни до обнуления не считаются |
| `lost_sales.horizon_months` | `ANALYTICS_LOST_SALES_HORIZON_MONTHS` | 1 | потерянные продажи: сколько месяцев назад искать продажи |

## Пороги показа (`analytics.display`)

| Ключ | Env | По умолчанию | Смысл |
|------|-----|--------------|-------|
| `dead_stock_display_days` | `ANALYTICS_DISPLAY_DEAD_STOCK_DAYS` | = `stock.dead_stock_days` (90) | неликвид: `days_since_last_sale >=` порога |
| `stockout_risk_days` | `ANALYTICS_DISPLAY_STOCKOUT_RISK_DAYS` | 14 | риск дефицита: `days_of_stock <=` порога |
| `table_limit` | `ANALYTICS_DISPLAY_TABLE_LIMIT` | 20 | максимум строк в таблице на странице (1..1000; топ и анти-топ — каждая); CSV-выгрузки не ограничены |
| `dead_stock_age_bounds` | `ANALYTICS_DISPLAY_DEAD_STOCK_AGE_BOUNDS` | `180,365` | границы корзин графика «Неликвиды по возрасту» (первая корзина — от `dead_stock_display_days`) |
| `days_of_stock_bounds` | `ANALYTICS_DISPLAY_DAYS_OF_STOCK_BOUNDS` | `8,15,31,61` | границы корзин графика «Дни до обнуления» (первая — от 0) |
| `turnover_bounds` | `ANALYTICS_DISPLAY_TURNOVER_BOUNDS` | `1,2` | границы корзин распределения оборачиваемости (корзины: 0; (0; 1); [1; 2); 2+) |
| `product_history_months` | `ANALYTICS_DISPLAY_PRODUCT_HISTORY_MONTHS` | 12 | карточка товара: сколько месяцев динамики показывать (включая выбранный) |
| `timezone` | `ANALYTICS_DISPLAY_TIMEZONE` | `Europe/Moscow` | часовой пояс времени расчёта на плашке свежести |
| `stale_after_hours` | `ANALYTICS_STALE_AFTER_HOURS` | 36 | через сколько часов после успешного расчёта данные помечаются устаревшими |

Границы корзин — по возрастанию.

## Пороги рекомендаций перемещений (`analytics.transfers`)

Покрытие = остаток / средние продажи в день (по метрике `days_of_stock`).
Требуется `deficit_days < target_days <= keep_days <= surplus_days`,
`min_quantity > 0` (иначе планировщик бросает `InvalidArgumentException` —
страница «Перемещения» отвечает ошибкой).

| Ключ | Env | По умолчанию | Смысл |
|------|-----|--------------|-------|
| `deficit_days` | `ANALYTICS_TRANSFER_DEFICIT_DAYS` | 14 | дефицит: покрытие `<=` порога |
| `target_days` | `ANALYTICS_TRANSFER_TARGET_DAYS` | 30 | получателю довозят до этого покрытия |
| `keep_days` | `ANALYTICS_TRANSFER_KEEP_DAYS` | 30 | донор не опускается ниже этого покрытия |
| `surplus_days` | `ANALYTICS_TRANSFER_SURPLUS_DAYS` | 60 | донор: покрытие `>=` порога |
| `min_quantity` | `ANALYTICS_TRANSFER_MIN_QUANTITY` | 1 | строки меньше (шт.) отбрасываются |
| `stock_surplus_min_stock` | `ANALYTICS_TRANSFER_STOCK_SURPLUS_MIN_STOCK` | 20 | ЭВРИСТИКА ДЛЯ ДЕМО: склад без продаж (нет строки `days_of_stock`) с остатком выше порога становится донором «по остатку» (`reason=stock_surplus`); финальное решение — при реальном клиенте, см. `docs/roadmap.md` |

Число строк на странице ограничивает `analytics.display.table_limit`.
