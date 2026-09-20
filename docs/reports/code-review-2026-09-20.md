# Отчёт: сверка кода с ТЗ и доками (code review, 2026-09-20)

Сам анализ — только чтением; по его итогам отдельными коммитами исправлены
докблоки (пп. 1.1, 1.2) и этот отчёт, п. 1.3 вынесен в Known issues roadmap.
Логика, тесты (кроме названия теста XyzClassifier), конфиг и мок не менялись. Метод: чтение всех
`docs/*` (stages, reports, features, roadmap, security, conventions,
README, CLAUDE.md), конфигов, кода (core, adapters, repositories,
commands, controllers, routes, middleware, views, компоненты виджетов),
тестов; сверка с эталонными отчётами этапов 01–14; полный прогон тестов
в Sail и `pint --test`. Проверено по HEAD `56707b3` (ветка
`stage-14-review-fixes`; коммит докблоков, родитель коммита этого отчёта).

---

## 1. Найденные расхождения «код ↔ доки»

### 1.1. `XyzClassifier` — докблок противоречит реализации (существенное)

Докблок `app/Core/Analytics/XyzClassifier.php:25-27` утверждает:

> Если по товару вообще нет продаж за период (mean=0), CV не определён
> математически — такой товар относится к Z (нестабильный спрос/его
> отсутствие), а **не отбрасывается**.

Фактическая реализация:

- `$byProductAndMonth` строится только из сделок (`XyzClassifier.php:56-70`):
  товар, по которому в периоде нет **ни одной сделки**, не попадает в
  итерацию вообще — его снэпшот **не записывается (отбрасывается)**, а не
  классифицируется как Z.
- Ветка `$mean <= 0.0 → Z` достижима только через сделки с нулевой суммой
  (покрыта тестом `XyzClassifierTest`: prod-3 с единственной сделкой на
  0.0 → Z), т.е. формально реализуется для «сделки на ноль», но **не** для
  товара без сделок вовсе, как обещает докблок.

Следствие: поведение для «товара без продаж» у XYZ-классификатора
отличается от описанного в докблоке. Для ABC/топа аналогичный эффект
(«потерянные продажи») уже зафиксирован в Known issues roadmap
(«Хвосты этапа 11», п.4), но для XYZ расхождения с текстом докблока в
доках не описано.

### 1.2. `MetricsSnapshotRepository::latestPeriodFor` — самопротиворечивый докблок (минор, документация)

`app/Core/Widgets/Contracts/MetricsSnapshotRepository.php:22-27`:

- Первый абзац (стр. 22–27): «Ключ `period` снэпшота, рассчитанного
  последним по факту (по моменту записи в БД — `created_at`, а не по
  значению `period`)».
- Абзац «Важно» (стр. 36–41): «„самый свежий" — это снэпшот с
  наибольшим `period_start` (момент записи `created_at` не учитывается)».

Второй (исправленный) абзац соответствует реализации:
`EloquentMetricsSnapshotRepository::latestPeriodFor()` сортирует
`orderByDesc('period_start')` (+ `id` как tie-break), а не по `created_at`.
Первый абзац — устаревший текст, который теперь вводит в заблуждение.
Сам текст докблока не переписывался (правило CLAUDE.md «этапы задним
числом не переписываются»), но в отличие от roadmap «Хвосты этапа 11»,
п.1 (где расхождение закрыто документально), прямо внутри контракта
осталась противоречащая строчка.

### 1.3. `CalculateMetrics` — привязка периода по умолчанию к деталям мока (минор, архитектура)

`app/Console/Commands/CalculateMetrics.php` (`resolvePeriod`): при
отсутствии аргумента `--period` период выводится из
`MockAdapter::historyEnd()`/истории через `instanceof`-проверку на
`MockAdapter`. Это связывает сервис расчёта с конкретным адаптером: при
появлении реальных адаптеров (Bitrix24/1C) логика «период по умолчанию»
будет знать о типе адаптера. Документация/ТЗ эту зависимость не
описывают.

### Закрытые пункты (учтены, правок не требуют)

- Расхождение «Хвосты этапа 11», п.1 (строка про `created_at DESC` в
  roadmap устарела) закрыто документально 2026-09-19/20. Остаточный
  устаревший текст был только в докблоке `latestPeriodFor` — см. п.1.2
  (исправлен в коммите `56707b3`).

---

## 2. Сверено и совпадает с ТЗ/доками (подтверждено)

### 2.1. Конфигурация `config/analytics.php`

| Параметр | Значение | Где зафиксировано |
|---|---|---|
| `stock.dead_stock_days` | 90 | features.md |
| `stock.days_of_stock_window` | 28 | этап 07 |
| `stock.min_in_stock_days` | 7 | этап 07 |
| `transfers.deficit_days / target_days / keep_days / surplus_days` | 14 / 30 / 30 / 60 | features.md, этап 14 |
| `transfers.min_quantity` | 1 | features.md, этап 14 |
| `display.dead_stock_display_days` | = `dead_stock_days` (90) | features.md |
| `display.stockout_risk_days` | 14 | features.md |
| `display.table_limit` | 20 | features.md |
| `display.dead_stock_age_bounds` | [180, 365] (первая корзина — от 90) | features.md |
| `display.days_of_stock_bounds` | [8, 15, 31, 61] | features.md |
| `display.turnover_bounds` | [1.0, 2.0] | features.md |
| `features.*` (dead_stock, stockout_risk, top_products, turnover, transfers) | все `true` | features.md |

Пороги ABC/XYZ в конфиг **не вынесены**: это константы классов —
`AbcClassifier` A ≤ 0.8, B ≤ 0.95 (накопленная доля), C — остаток;
`XyzClassifier` X ≤ 0.10, Y ≤ 0.25 (CV), иначе Z. Секций `abc`,
`turnover`, `widgets` в `config/analytics.php` нет. Таблица сверена с
файлом конфига целиком; в первой версии отчёта были ошибочные строки
(ABC 50/25, `abc.*`/`turnover.bounds`/`widgets.widgets.*` как ключи конфига,
`dead_stock_age_bounds` = [90, 180, 365], `display.days_of_stock`,
`stock.stockout_risk_days`, «пороги 30/60» для transfers) — исправлены.

Значения из таблицы совпадают с конфигом и с docs/features.md.

### 2.2. ABC/XYZ классификация

Сверено с кодом:

- `app/Core/Analytics/AbcClassifier.php`: A ≤ 0.8, B ≤ 0.95 накопленной
  доли, остальное — C.
- `app/Core/Analytics/XyzClassifier.php`: CV по всем месяцам периода
  (месяцы без продаж = 0), X ≤ 0.10, Y ≤ 0.25, иначе Z; расхождение
  докблока с кодом — п. 1.1 (исправлено).

Не проверялось: соответствие ТЗ этапа 04 построчно, округление метрик.

### 2.3. Оборачиваемость

Сверено с `app/Core/Analytics/TurnoverCalculator.php` (и
`MetricsCalculationService.php:93-96`):

- считается по (товар, месяц) в **штуках**: `turnover = units_sold /
  avgStock`, `units_sold` — сумма `−quantity` движений типа `Sale`;
  выручка по сделкам не используется.
- `avgStock = (opening + closing) / 2`; `opening` первого месяца — остаток
  на (начало диапазона − 1 день) суммарно по всем складам, его
  `MetricsCalculationService` берёт одним `fetchStock()`; дальше
  `opening` = `closing` предыдущего месяца.
- `transfer_in`/`transfer_out` в сальдо пропускаются; при `avgStock <= 0`
  снэпшот не пишется.

Не проверялось: совпадение с отчётом этапа 09 и docs/features.md.

### 2.4. Мок

Сверено с `app/Adapters/MockAdapter.php`,
`app/Adapters/Mock/MockScenarioManifest.php`:

- Детерминизм: `randomizerFor()` — `Mt19937(crc32(seed:context))`; сделки
  месяца генерируются из контекста `deals:YYYY-MM`.
- `fetchDeals()` ограничен окном истории (`MockAdapter.php:166`,
  `min(..., historyEnd)`).
- `fetchStock()` отдаёт остатки по каждой паре товар×склад, включая
  нулевые; считается суммой тех же движений, что отдаёт
  `fetchStockMovements()`.
- Регулярные товары: перемещение между складами с вероятностью 1/45 в
  день (`regularMovements`, `getInt(1, 45) === 1`) при нескольких
  складах.
- Сценарии в манифесте: dead (`deadProducts`, `deadAges`; возрасты по
  умолчанию `[100, 250]`), near_zero, gaps, spike, seasonal, imbalance.
  Отдельных «нулевых» сценариев нет.

Не проверялось: неотрицательность остатков на всей истории (заявлено в
докблоке), значения по умолчанию для gap/spike, тесты манифеста.

### 2.5. Перемещения (этап 14)

Сверено с `app/Core/Transfers/TransferPlanner.php`:

- Валидация в конструкторе: `deficit < target <= keep <= surplus`,
  `min_quantity > 0`; параметра `max_quantity*` нет.
- Пары со скоростью ≤ 0 или отрицательным остатком игнорируются.
- Дефицит: покрытие ≤ `deficit_days`; потребность = скорость ×
  `target_days` − остаток. Дефициты по возрастанию покрытия (ничья —
  `warehouseId`, `strcmp`).
- Донор: покрытие ≥ `surplus_days`; доступно = остаток − скорость ×
  `keep_days`. Для каждого дефицита доноры пересортировываются по
  **убыванию оставшегося доступного объёма** (ничья — `warehouseId`),
  берётся `floor(min(потребность, доступно))`; строки меньше
  `min_quantity` пропускаются без траты донора.
- «Покрытие после» — нарастающим итогом у получателя и у донора.
- Ограничение v1 (пара без продаж не даёт строки `days_of_stock` и не
  бывает донором) зафиксировано в roadmap (Known issues).

Не проверялось: пересчёт чисел отчёта этапа 14 (Small/Medium),
`TransferRecommendationService` и его тесты.

### 2.6. Виджеты / флаги

Сверено с `routes/web.php`, `OverviewDashboardController.php`,
`ResolvesMonthPeriod.php`, `WidgetDataProvider.php`:

- Роуты дашбордов под `auth`; `stock` — `feature:dead_stock,stockout_risk`,
  `top-products`, `turnover`, `transfers` — по своему флагу; `overview`
  и `abc-xyz` без флагов.
- `?period=` — только `month:YYYY-MM`, иначе 404 (`ResolvesMonthPeriod`).
- Overview: окно 6 месяцев (`OVERVIEW_MONTHS`), конец — `?period` либо
  последний период метрики `revenue`. KPI-карточка сравнивается с
  **предыдущими 6 месяцами** (`precedingPeriodKeys`); график `lineChartYoY`
  — с **тем же окном год назад** (`previousYear()`); плюс таблица.

Не проверялось: подключение Chart.js (локально или CDN), визуальная
иерархия компонентов, виджеты топ/анти-топ.

### 2.7. Аутентификация и безопасность

Сверено с кодом:

- `config/fortify.php`: `features = []`; `FortifyServiceProvider`:
  лимитер `login` — 5 попыток в минуту на ключ email|IP.
- `routes/web.php`: `/` → редирект на `/dashboards/overview`, который
  под `auth`, поэтому гость попадает на `/login` (цепочка `/` → overview
  → login); дашборды закрыты `auth`.
- `bootstrap/app.php`: `/up` — health-роут фреймворка (auth-middleware к
  нему не применяется).
- Есть команды `users:create` и `users:password`.

Не проверялось: `secure cookie`, соответствие docs/security.md,
интерактивный ввод пароля.

---

## 3. Run book

Тесты (в Sail, `pgsql`, Fortify Local):

```
$ ./vendor/bin/sail artisan test
```

Полный прогон (эталонный, без исключений): `./vendor/bin/sail artisan test`.

- до правок (HEAD `a37147c`): `tests 440, passed 440, assertions 95355`, 134.3 с;
- после коммита докблоков (`56707b3`): `tests 440, passed 440, assertions 95355`, 139.0 с.

Динамика полного прогона по отчётам этапов: этап 12 — 371 тест /
76378 assertions; этап 13 — 403 / 93708; этап 14 — 440 / 95355.
Скачок assertions (76378 → 93708) приходится на этап 13, а не на этап 14
(причину в отчёте этапа 13 в рамках этого ревью не разбирали); на
этапе 14 рост небольшой (+1647). Без группы `slow` на этапе 13 было
393 / 76417, на этапе 14 — 429 / 77960.

Pint (`./vendor/bin/sail bin pint --test`): `{"tool":"pint","result":"passed"}`.

---

## 4. Статус рекомендаций

1. `XyzClassifier`: докблок приведён к коду («нет сделок → снэпшота нет;
   нулевая сумма → Z»), логика не менялась. Название теста
   `XyzClassifierTest` уточнено (было «no-demand product», по факту
   проверяются сделки с нулевой суммой). Что считать контрактом для
   товара вообще без сделок — остаётся открытым (связано с «потерянными
   продажами», Хвосты этапа 11, п.4).
2. Докблок `latestPeriodFor` переписан под `period_start DESC`.
3. `resolvePeriod` / `instanceof MockAdapter`: записано в Known issues
   roadmap; решение — при появлении реальных адаптеров (этапы 15/16).

---

## 5. Резюме

Проект в целом соответствует ТЗ и докам: ключевые метрики (ABC/XYZ,
оборачиваемость, неликвиды, дни запаса), перемещения, мок, виджеты,
аутентификация, флаги и безопасность реализованы и покрыты тестами
(440 passed). Найден один существенный разрыв док↔код (`XyzClassifier`,
п.1.1) и два минорных (устаревший докблок `latestPeriodFor`, п.1.2;
привязка `CalculateMetrics` к `MockAdapter`, п.1.3). Пп. 1.1 и 1.2
исправлены, п. 1.3 отложен в Known issues. Таблица конфигов в первой версии
отчёта содержала ошибки и исправлена по `config/analytics.php`.
