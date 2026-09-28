# Этап 29: пороги аналитики в `.env` — отчёт

## 1. Что сделано

| Файл | Что |
|---|---|
| `app/Support/EnvNumber.php` | `int(key, default, min, max)`, `intList`, `floatList`: пусто — по умолчанию; не число, дробь для целого, `true`/`false`, пустой элемент списка, выход за границы — `InvalidArgumentException` с именем переменной |
| `config/analytics.php` | 17 порогов через `EnvNumber`; значения по умолчанию не изменились |
| `docs/features.md` | раздел «Настройка порогов под проект», таблица порогов расчёта, колонка «Env» у порогов показа и перемещений |
| `.env.example` | закомментированные основные переменные |

Переменные: `ANALYTICS_DEAD_STOCK_DAYS`, `ANALYTICS_DAYS_OF_STOCK_WINDOW`,
`ANALYTICS_MIN_IN_STOCK_DAYS`, `ANALYTICS_LOST_SALES_HORIZON_MONTHS`,
`ANALYTICS_TRANSFER_{DEFICIT,TARGET,KEEP,SURPLUS}_DAYS`,
`ANALYTICS_TRANSFER_MIN_QUANTITY`, `ANALYTICS_TRANSFER_STOCK_SURPLUS_MIN_STOCK`,
`ANALYTICS_DISPLAY_{DEAD_STOCK_DAYS,STOCKOUT_RISK_DAYS,TABLE_LIMIT,PRODUCT_HISTORY_MONTHS}`,
`ANALYTICS_DISPLAY_{DEAD_STOCK_AGE,DAYS_OF_STOCK,TURNOVER}_BOUNDS`;
`ANALYTICS_STALE_AFTER_HOURS` переведён на тот же разбор.

## 2. Решения

| Вопрос | Решение | Почему |
|---|---|---|
| Где настраивать | `.env`, как источник, флаги и время расчёта | на каждом проекте свой `.env`; код одинаковый |
| Ошибка в значении | исключение при загрузке конфига, а не `(int) 'abc'` = 0 | ноль в пороге молча меняет смысл страниц (например, неликвидом становится всё) |
| Диапазоны | дни, штуки, месяцы — от 1 (остаток донора и риск дефицита — от 0); `table_limit` — 1..1000, как принимает репозиторий | отсекают заведомо бессмысленные значения |
| Согласованность порогов перемещений | не проверяется при загрузке | её уже проверяет `TransferPlanner` (единственное место правила); при нарушении падает только страница «Перемещения» |
| Порог показа неликвида | по умолчанию следует `ANALYTICS_DEAD_STOCK_DAYS`, можно задать отдельно | как было: один порог, если не нужен другой |

## 3. Тесты

`tests/Unit/Support/EnvNumberTest.php`: значение по умолчанию (нет / пусто),
разбор чисел и списков, восемь видов ошибок (с именем переменной в
сообщении), загрузка `config/analytics.php` с переменными окружения (в т.ч.
порог показа неликвида следует порогу расчёта). Полный прогон (включая slow),
Pint и PHPStan — зелёные.

## 4. Что осталось

Развёрнутый стенд: миграций нет; новые переменные необязательны. После
правки `.env` — `php artisan config:cache`; пороги расчёта действуют после
следующего `metrics:calculate`.
