# Этап 12: витрина на моке — графики, оборачиваемость, YoY, демо-режим

Ветка `stage-12-showcase` (от `stage-11-followups`, она не слита). Реальные адаптеры
сдвинуты на этапы 13/14 (roadmap, CLAUDE.md, комментарий в `config/analytics.php`).
Файл этапа — `docs/stages/stage-12-showcase.md`.

## 1. Что сделано

- **A. Оборачиваемость.** `/dashboards/turnover` (`dashboards.turnover`, флаг `turnover`,
  env `ANALYTICS_FEATURE_TURNOVER`, пункт навигации «Оборачиваемость»). Таблицы «Самая низкая»
  и «Самая высокая» (товар, остаток на конец месяца, продано, оборачиваемость — штуки, из
  `value_meta`: `closing_stock`, `units_sold`), лимит `analytics.display.table_limit`; график
  распределения по корзинам 0 / 0–1 / 1–2 / 2+ (границы в config). Период — как на других
  страницах (`latestPeriod`, `?period=month:YYYY-MM`, общая валидация).
- **B. Графики.** `/dashboards/stock`: «Неликвиды по возрасту» и «Дни до обнуления»;
  `/dashboards/top-products`: горизонтальный столбчатый график топ-N; overview: график
  динамики за 6 месяцев уже был (см. разведку) — добавлен только тест. Корзины считаются
  одним агрегатным SQL-запросом (`COUNT(*) FILTER`), не в PHP. Данные для графиков — тот же
  `LineChartData`, компонент `bar-chart` получил необязательные `horizontal` и `note`. Пустые
  данные: график не рисуется. Названия попадают в JS через `@json` (JSON_HEX_TAG…); тест на
  экранирование таблиц остался, добавлен тест на JSON в графике. График скрыт вместе с виджетом
  (флаг).
- **C. YoY.** `/dashboards/top-products?base=previous|year_ago` (по умолчанию previous;
  иное значение → 404). Переключатель показывается, только если у какого-то товара есть выручка
  в том же месяце прошлого года. При прямом заходе на `year_ago` без такой базы — страница
  200 с пояснением «Нет данных за прошлый год» и ссылкой на сравнение с предыдущим месяцем.
  Заголовки колонок: «к пред. месяцу» / «к тому же месяцу прошлого года».
- **D. Демо-режим.** `demo:install` (`app/Console/Commands/InstallDemo.php`), `docs/demo.md`,
  указатель в CLAUDE.md. Удалены `/demo/widgets` (роут, контроллер, view, запись в тесте
  авторизации, упоминание в `docs/security.md`).
- **E. Целостность демо.** `tests/Feature/DemoIntegrityTest.php` + расширен тест на N+1.

## 2. Разведка

- **(а) turnover на страницах:** нигде не выводился (только `TurnoverCalculator`,
  `MetricsCalculationService`, `metrics:calculate`) → страница добавлена.
- **(б) ABC-XYZ:** матрица (`MatrixData`): строки × столбцы классов, в ячейке «число товаров /
  сумма value», пустые ячейки — «—». На Small после `demo:install` заполнены 3 ячейки.
- **(в) Chart.js:** один `<script src="https://cdn.jsdelivr.net/npm/chart.js@4">` в
  `standalone.blade.php`; npm-пакета нет. Компоненты `line-chart` и `bar-chart` (вертикальный)
  принимают `LineChartData`, конфигурируют Chart.js инлайн-скриптом с `@json`. Новые графики
  используют `bar-chart` (без дублирования), горизонтальность — через prop.
  Следствие: для демо нужен доступ к CDN.
- **(г) метрики и периоды** (прогон на тестовой БД, seed 1):
  - Small: 12 месяцев (2025-09…2026-08); revenue 594 строки, turnover 600, days_since_last_sale
    596, days_of_stock 1112 (product_warehouse), abc_xyz_classification 50 (один период).
    Год назад для 2026-08 отсутствует.
  - Medium: история 24 месяца; `metrics:calculate` по умолчанию считает 12 (2025-09…2026-08) →
    год назад недоступен. Прогон на всю историю (2024-09…2026-08) занял ≈47 с (по умолчанию ≈30 с):
    revenue 12 000, turnover 12 000, days_since_last_sale 11 995, days_of_stock 35 156; год
    назад доступен для месяцев с 2025-09.
  - Максимумы на моке: days_since_last_sale ≤ 124, turnover ≤ 2.8 (Small) / 2.0 (Medium).

## 3. Страницы и роуты до/после

| Роут | До | После |
|------|----|-------|
| `/dashboards/overview`, `/dashboards/abc-xyz` | auth | auth (без изменений) |
| `/dashboards/stock` | auth + флаг `dead_stock` или `stockout_risk` | без изменений; добавлены графики (каждый — по своему флагу) |
| `/dashboards/top-products` | auth + флаг `top_products` | без изменений; график, `?base=` |
| `/dashboards/turnover` | — | auth + флаг `turnover` (новый) |
| `/demo/widgets` | auth | удалён (404) |

## 4. Новые сигнатуры и config

```php
// MetricsComparisonRepository (новый метод; существующие не менялись)
/** @param list<ValueRange> $ranges @return list<int> — счётчики в порядке ranges, один агрегатный запрос */
public function bucketCounts(string $metricKey, string $entityType, Period $period, array $ranges): array;

// DTO: ValueRange(?float $min, bool $minInclusive, ?float $max, bool $maxInclusive)
//      ::exactly(float), ::halfOpen(float $min, ?float $max) — [min; max), ::open(float $min, ?float $max) — (min; max)
// DTO: TurnoverRow(productId, productName, ?closingStock, ?unitsSold, turnover)

// ProductTablesProvider
public function turnover(?Period $period, Direction $dir, int $limit): RankedTableData;
public function topProducts(?Period $period, Direction $dir, int $limit, ComparisonBase $base = ComparisonBase::Previous): RankedTableData;
public function hasRevenueBase(Period $period, ComparisonBase $base): bool;

// ProductChartsProvider (новый): все возвращают ?LineChartData (null — нет данных)
turnoverDistribution(?Period $period, array $bounds); deadStockAge(?Period $period, int $thresholdDays, array $bounds);
daysOfStock(?Period $period, array $bounds); topRevenue(RankedTableData $top);
```

Config (`analytics`):

```php
'features' => [..., 'turnover' => (bool) env('ANALYTICS_FEATURE_TURNOVER', true)],
'display' => [
    'dead_stock_age_bounds' => [180, 365],   // корзины: [dead_stock_display_days; 180), [180; 365), 365+
    'days_of_stock_bounds'  => [8, 15, 31, 61], // 0–7, 8–14, 15–30, 31–60, 61+
    'turnover_bounds'       => [1.0, 2.0],   // 0; (0; 1); [1; 2); 2+
],
```

Команда: `demo:install {--email=}`.

## 5. Изменённые существующие тесты

- `FeatureMiddlewareTest` («has all flags on by default»): в ожидаемый массив флагов добавлен
  `turnover` — новый флаг.
- `StandaloneAuthTest`: из списка standalone-путей убран `/demo/widgets` (роут удалён), добавлен
  `/dashboards/turnover`.
- `StockAndTopPagesTest`, тест на число SQL-запросов: в сид добавлен `turnover`, в список страниц —
  `/dashboards/turnover` (требование п. E).

Остальные существующие тесты не менялись.

## 6. Расхождения с заданием и решения

1. **Период `demo:install`.** Вместо «периода по умолчанию» считается вся история мока одним
   прогоном (`YYYY-MM:YYYY-MM` от `historyStart` до `historyEnd`). Причина: на Medium по
   умолчанию 12 месяцев, и YoY в демо было бы недоступно. Для Small результат тот же, что по
   умолчанию (12 месяцев). Цена: Medium считается дольше (≈47 с).
2. **Корзина «60+».** В задании «31–60» и «60+» пересекаются; сделано 31–60 и «61+»
   (границы `[8, 15, 31, 61]`, подписи по полным дням, значение 60.5 попадает в «31–60»).
3. **Формат config** — границы (нижние границы корзин), а не готовые диапазоны; подписи и
   диапазоны строит провайдер. Первая корзина неликвидов начинается с
   `dead_stock_display_days`, дней до обнуления — с 0, оборачиваемости — с точного нуля.
4. **`no_sales_in_lookback`.** Значение метрики у таких товаров — уже нижняя граница, поэтому
   отдельной логики в корзинах нет; в подписи графика стоит постоянное пояснение (не зависит от
   наличия таких товаров — счёт для этого потребовал бы ещё одного запроса).
5. **Overview.** График динамики за 6 месяцев уже есть (`line-chart`, плюс `bar-chart` «год к
   году») — изменений нет, добавлен тест (2 canvas, 6 точек).
6. **`year_ago` без данных.** Вместо таблиц и графика показывается только пояснение со ссылкой
   назад (график/таблицы с пустыми дельтами не имеют смысла).
7. **Наличие базы** определяется существующим `top()` (limit 1, ранжирование по дельте с базой),
   интерфейс для этого не расширялся.
8. **Пароль в `demo:install`** запрашивает вложенная `users:create` (та же валидация и
   подтверждение); при существующем пользователе команда его не трогает.
9. **README** (стандартный Laravel) не менялся; вместо него — `docs/demo.md` и указатель в CLAUDE.md.
10. Упоминания `/demo/widgets` в отчётах и файлах прошлых этапов оставлены (правило «задним
    числом не переписывать»).

## 7. Результаты тестов

Команды: `./vendor/bin/sail artisan test`, `... --exclude-group=slow`,
`sail exec laravel.test vendor/bin/pint --test`. Тесты только на БД `testing`.

- Шаг 0 (docs): тесты не запускались.
- A (`feat(turnover)`): `tests":344,"passed":344,"assertions":76245,"duration_ms":54327`; pint: `PASS 150 files`.
- B (`feat(charts)`): `tests":352,"passed":352,"assertions":76282,"duration_ms":54857`; отдельный `pint --test` на этом коммите не читался (вывод был пуст), файлы проверены `pint --dirty` и следующим `--test`.
- C (`feat(top-products)`): `tests":361,"passed":361,"assertions":76314,"duration_ms":55225`; pint: `PASS 152 files`.
- D (`feat(demo)`): `tests":367,"passed":367,"assertions":76335,"duration_ms":63128` (повторно 62260); pint: `PASS 153 files`.
- E (`test(demo)`): `tests":371,"passed":371,"assertions":76378,"duration_ms":68466`; pint: `PASS 154 files`.
- `--exclude-group=slow` (на дереве E): `tests":366,"passed":366,"assertions":73847,"duration_ms":48009`.

Время: полный прогон ≈ 68 с, без slow ≈ 48 с.

## 8. Что проверить глазами

Я не открывал страницы в браузере: проверены структура HTML и данные, но не внешний вид.

1. Все графики реально рисуются (Chart.js с CDN; нужен интернет), в консоли браузера нет ошибок.
2. `/dashboards/turnover`: подписи корзин «0 / 0–1 / 1–2 / 2+», высота canvas, отступы; таблицы.
3. `/dashboards/stock`: два графика перед таблицами, читаемость подписей корзин, примечания
   (`.widget-note` без специального CSS — обычный абзац).
4. `/dashboards/top-products`: горизонтальный график — влезают ли длинные названия по оси Y,
   порядок баров (от большего к меньшему сверху), высота при 20 строках.
5. Переключатель базы (виден только при данных прошлого года, т. е. на Medium для месяцев с
   2025-09) и страница с пояснением при `?base=year_ago` на Small; заголовки колонок дельт
   (длинные — не ломают ли ширину таблицы).
6. Навигация: теперь 5 пунктов — помещаются ли в шапку на узком экране.
7. Overview: два графика (динамика и «год к году») без изменений — подписи на английском (см. п. 9).

## 9. Замеченные проблемы вне скоупа

- Заголовки и подписи графиков overview — английский ключ метрики (`revenue`).
- `MockAdapter::fetchDeals()` генерирует значения в зависимости от запрошенного диапазона, поэтому
  `metrics:calculate` за разные периоды даёт разную выручку одного и того же месяца (виден в
  разведке: min/max revenue в прогонах отличались). Для демо это обходится одним прогоном по всей
  истории, но накладывание прогонов разных периодов в одной БД даёт несогласованную историю.
- `.env.example` без `ANALYTICS_*` и с `DB_CONNECTION=sqlite`; README — стандартный Laravel.
- Chart.js подключён с CDN (без сети графиков нет) — офлайн-демо потребует npm-пакета
  (в этой задаче пакеты ставить запрещено).
- На моке нет неликвидов старше 124 дней: корзины 180–364 и 365+ всегда пусты.
- Формат ячеек ABC/XYZ-матрицы — «число / value» с `number_format`, единицы не подписаны.

## 10. Открытые вопросы

- Устраивает ли, что `demo:install` считает всю историю (Medium ≈ 47 с), а не 12 месяцев?
- Подключить Chart.js через npm/Vite (локально, без CDN) отдельным этапом?
- Нужен ли сброс демо-данных (`demo:reset`), пока команды удаления нет?
