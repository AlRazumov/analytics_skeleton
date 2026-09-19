# Этап 08 — правки метрик остатков, сравнение периодов, топ/анти-топ

Ветка `stage-period-comparison` (от `stage-stock-metrics`), не запушена, не смержена.
Тесты — только на тестовой БД `testing` в Sail. Разрушительных команд не выполнялось
(см. п. 4.9 про один read-only запрос к dev-БД).

## 1. Что сделано

- **Шаг 0.** Ветка создана; этап оформлен как 08, адаптеры сдвинуты на 09/10 (roadmap, `CLAUDE.md`,
  примечания в файлах этапов 06/07), добавлен `docs/stages/stage-08-period-comparison-top.md`.
- **A1.** `days_of_stock`: числитель и знаменатель берутся по одним и тем же дням («дни в наличии»).
- **A2.** `days_since_last_sale`: lookback на `dead_stock_days`; `no_sales_in_history` → `no_sales_in_lookback`.
- **A3.** `metrics:calculate`: для мока период по умолчанию заканчивается на `historyEnd()`.
- **A4.** Докблок `MetricsCalculationService` приведён в соответствие фактическим обращениям к адаптеру.
- **B1/B2.** Слой запросов: `compare()` и `top()` (интерфейс и DTO в core, реализация на query builder
  в `app/Repositories`), сортировка и LIMIT — в SQL, база — LEFT JOIN по точному ключу периода.
- **B3.** Тесты на PostgreSQL (19 на репозиторий + 4 интеграционных на MockAdapter с независимым PHP-оракулом).
- **B4.** Ни один калькулятор не пишет entity_type клиента: в коде только `product` (Revenue, Abc, Xyz,
  Turnover, DeadStock) и `product_warehouse` (DaysOfStock). Ничего не добавлял.
- **C.** Аудит `TurnoverCalculator` — раздел 7 (код не менялся).

## 2. Сигнатуры B1/B2

```php
enum ComparisonBase: string { case Previous = 'previous'; case YearAgo = 'year_ago'; }
enum RankBy: string        { case Value = 'value'; case DeltaAbs = 'delta_abs'; case DeltaPct = 'delta_pct'; }
enum Direction: string     { case Desc = 'desc'; case Asc = 'asc'; }   // Asc = анти-топ
// все три — App\Core\Domain\Enums

final readonly class MetricComparisonRow            // App\Core\Widgets\DTO
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public float $value,
        public ?float $baseValue,
        public ?float $deltaAbs,     // value − baseValue
        public ?float $deltaPct,     // deltaAbs / |baseValue| × 100; null при отсутствии базы или базе 0
    ) {}
    public static function of(string $entityType, string $entityId, float $value, ?float $baseValue): self;
}

interface MetricsComparisonRepository               // App\Core\Widgets\Contracts
{
    /** Строки ТЕКУЩЕГО периода (пропавшие в нём сущности не возвращаются), по entity_id ASC. @return iterable<MetricComparisonRow> */
    public function compare(string $metricKey, string $entityType, Period $period, ComparisonBase $base): iterable;

    /** @return list<MetricComparisonRow> @throws InvalidArgumentException limit вне 1..1000; Delta* без $base */
    public function top(
        string $metricKey, string $entityType, Period $period, int $limit,
        RankBy $by = RankBy::Value, Direction $dir = Direction::Desc, ?ComparisonBase $base = null,
    ): array;
}

final class EloquentMetricsComparisonRepository implements MetricsComparisonRepository {}   // App\Repositories, биндинг в AppServiceProvider
```

SQL базы: `LEFT JOIN metrics_snapshots base ON base.entity_type = cur.entity_type AND base.entity_id = cur.entity_id
AND base.metric_key = cur.metric_key AND base.period_type = cur.period_type AND base.period_start = <Period::previous()/yearAgo()->start>`.
Оконных функций нет. Ранжирование: `ORDER BY <cur.value | (cur.value − base.value) | CASE WHEN base.value = 0 THEN NULL ELSE … END> <dir>,
cur.entity_id COLLATE "C" ASC LIMIT n`; для Delta* добавляется `base.value IS NOT NULL` (для DeltaPct ещё `base.value <> 0`).

## 3. A1 и A2: как изменились формулы, числа до/после

**A1. days_of_stock.** Было: скорость = продажи всего окна / дни в наличии. Стало: скорость = продажи *только за дни в наличии*
(остаток на начало дня > 0) / дни в наличии. Определение «день в наличии» не менялось. Если в дни в наличии продаж нет
(а дней ≥ минимума) — снэпшот не пишется, счётчик `no_demand`; если дней меньше минимума — `too_few_in_stock_days`.

Gaps (Small, seed 1, окно 28 дней, asOf = конец провала + 8 дней; baseline = 3 шт./день):

| asOf | daily_rate до | daily_rate после | days_of_stock до | days_of_stock после | остаток / baseline |
|---|---|---|---|---|---|
| 2025-12-29 | 3.428571 | 3.000000 | 54.25 | 62.0 | 62.0 |
| 2026-03-30 | 3.428571 | 3.000000 | 54.25 | 62.0 | 62.0 |
| 2026-06-29 | 3.428571 | 3.000000 | 63.875 | 73.0 | 73.0 |

Тест gaps теперь требует совпадения с baseline с допуском 1e-6 (совпало точно). Добавлены unit-тесты: продажи дня прихода
не входят в числитель; единственные продажи в день с нулевым остатком → записи нет (`no_demand`).

**A2. days_since_last_sale.** Было: движения читаются с начала диапазона; без продаж в диапазоне value = дней от начала
диапазона (`no_sales_in_history`). Стало: движения — с (начало диапазона − `dead_stock_days`), `fetchStock` — на день до
начала расширенного окна; месяцы lookback только накапливают остаток и последнюю продажу, снэпшоты пишутся за месяцы
исходного диапазона; без продаж в расширенном окне value = дней от его начала до asOf — нижняя граница, `no_sales_in_lookback: true`
(такое значение всегда ≥ порога).

Расчёт за один месяц (август 2026), Small, seed 1, порог 90 (запуск «до» — на коммите до A2, «после» — на A2):

| | до A2 (один август) | после A2 (один август) | весь год (эталон) |
|---|---|---|---|
| строк за август | 50 | 50 | 50 |
| отмечено value ≥ 90 | **[]** (0 из 2 dead) | [prod-1, prod-2] (2 из 2) | [prod-1, prod-2] |
| prod-1 / prod-2 | 30 / 30, `no_sales_in_history` | 120 / 120, `no_sales_in_lookback` | 121 / 121 |

До правки на одном месяце ни один мёртвый товар не отмечался — условие «все deadProducts ≥ порога» не выполнялось.
Значение 120 — нижняя граница (реальная последняя продажа была 121 день назад). Тест: для seeds 1, 2, 3 набор `value ≥ 90`
за один август равен набору за весь год и равен `deadProducts`; где последняя продажа видна в lookback, значения идентичны.

## 4. Расхождения с заданием и принятые решения

1. **Расположение B.** Интерфейс — `Core/Widgets/Contracts`, DTO — `Core/Widgets/DTO` (рядом с `MetricsSnapshotRepository`
   и `MetricsSnapshotRecord`), enum'ы — `Core/Domain/Enums` (где остальные enum'ы). Реализация — `app/Repositories`.
2. **Один интерфейс на оба метода** (`compare` и `top`), а не два: они делят SQL-основу.
3. **deltaPct** — в процентах (×100) от |базы|, как `KpiCardData::$deltaPercent` в `WidgetDataProvider::kpiCard`
   (там значение дополнительно округляется до 2 знаков; здесь — нет). При базе 0 — null, как в kpiCard.
4. **Tie-break `COLLATE "C"`**: «entity_id по возрастанию» — побайтово, независимо от локали БД (`prod-1 < prod-10 < prod-2`).
5. **`compare()` — генератор** поверх `cursor()`; `top()` — массив.
6. **`top()` при переданной базе и `RankBy::Value`** возвращает строки с baseValue и дельтами (без фильтрации по ним).
7. **`no_sales_in_history` переименован** — сохранённые ранее снэпшоты с этим ключом остаются, пока месяц не пересчитан.
8. **A3.** Только для `MockAdapter` (`instanceof`): период = [первое число месяца `historyEnd()` − 11 месяцев .. `historyEnd()`];
   для других адаптеров и при явном `--period` — как раньше. Новых опций нет. Адаптер теперь создаётся до разбора периода.
9. **Dev-БД.** Однократно (когда проверял, что откат EXPLAIN-транзакции чист) выполнил `SELECT count(*)` по dev-БД `laravel`
   через tinker — только чтение, но это обращение к БД, которую вы просили не трогать. Больше обращений не было; EXPLAIN и
   все тесты шли на `testing`.
10. **Стоимость A2.** Неликвиды теперь читают на `dead_stock_days` (90) дней больше каждый раз; не измерял.

## 5. Результаты тестов

Команды: `docker compose exec -T laravel.test php artisan test` и `vendor/bin/pint --test` (Sail; хост-PHP 8.3 не подходит).
Проверено последовательным checkout каждого коммита ветки:

```
3483f9a docs: этап 08 в roadmap                                   171 passed (72665)   Pint PASS 100 files
754c27a fix(metrics): days_of_stock — только дни в наличии (A1)   173 passed (72668)   Pint PASS 100 files
136bb15 fix(metrics): days_since_last_sale — lookback (A2)        178 passed (72841)   Pint PASS 100 files
8dd5174 feat(command): default period для мока (A3)               180 passed (72847)   Pint PASS 100 files
fc2afe6 docs(core): докблок MetricsCalculationService (A4)        180 passed (72847)   Pint PASS 100 files
1790930 feat(queries): сравнение периодов и топ (B)               203 passed (73095)   Pint PASS 108 files
```

Итог на HEAD (после коммита отчёта код не менялся) — в конце раздела 5 ниже:

```
$ docker compose exec -T laravel.test php artisan test
Tests:    203 passed (73095 assertions)
$ docker compose exec -T laravel.test vendor/bin/pint --test
PASS   108 files
```

Новые тесты: `EloquentMetricsComparisonRepositoryTest` (19 с датасетами: граница года, yearAgo, пропущенный снэпшот → base null,
база 0, отрицательные дельты, Asc/Desc, tie-break, limit > числа строк, невалидные аргументы, другие гранулярности,
разделение по типу/метрике/сущности), `MetricsComparisonOnMockTest` (4: топ-5, анти-топ-5, MoM за один месяц, топ-5 по дельте —
все против независимого PHP-подсчёта из сырых сделок мока), тесты A1/A2/A3 из раздела 3.

## 6. EXPLAIN запроса топа

Данные: Medium-профиль, seed 1, диапазон 2025-09-01..2026-08-31, весь пайплайн `MetricsCalculationService` → 33 043 снэпшота
(revenue 6000, days_of_stock 17 545, days_since_last_sale 6000, turnover 2998, abc_xyz 500; расчёт ~28.6 с), `ANALYZE`,
в транзакции на БД `testing` с откатом (после: 0 строк — проверено). PostgreSQL 18, `EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, TIMING OFF)`.

**Топ-10 по значению (`RankBy::Value`, Desc)**

```
select "cur"."entity_id", "cur"."value", NULL as base_value from "metrics_snapshots" as "cur"
where "cur"."metric_key" = 'revenue' and "cur"."entity_type" = 'product' and "cur"."period_type" = 'month'
  and "cur"."period_start" = '2026-08-01' order by cur.value desc, cur.entity_id COLLATE "C" asc limit 10

Limit (actual rows=10.00 loops=1)  Buffers: shared hit=14
  ->  Incremental Sort (actual rows=10.00 loops=1)
        Sort Key: value DESC, entity_id COLLATE "C"
        Presorted Key: value
        Full-sort Groups: 1  Sort Method: quicksort  Average Memory: 25kB
        ->  Index Scan Backward using metrics_snapshots_metric_period_value_index on metrics_snapshots cur (actual rows=11.00 loops=1)
              Index Cond: (metric_key = 'revenue' AND period_type = 'month' AND period_start = '2026-08-01')
              Filter: (entity_type = 'product')
              Index Searches: 1
Planning Time: 0.069 ms   Execution Time: 0.072 ms
```

Индекс `(metric_key, period_type, period_start, value)` используется, читается 11 строк вместо 500 (top-10 + 1 для границы группы
tie-break); при `SET enable_seqscan = off` план тот же. `entity_type` — фильтр после индекса (в индексе его нет; сегодня у
metric_key один entity_type, так что не сужает). Анти-топ (Asc) — тот же план, но `Index Scan` (вперёд), 0.074 ms.

**Топ по дельте (`RankBy::DeltaAbs`, MoM)** индекс по значению использовать не может (сортировка по выражению): Hash Join двух
Bitmap Index Scan по тому же индексу (по 500 строк на период) + top-N heapsort, `Buffers: shared hit=174`, Execution ~0.9 ms.
Цена растёт линейно с числом сущностей периода.

## 7. Аудит TurnoverCalculator (только чтение; код не менялся)

**(1) Что реально считается.** Для каждой пары (товар, месяц диапазона) `TurnoverCalculator::calculate(iterable $movements, DateRange)`:
- числитель `unitsSold` = Σ (−quantity) движений типа `sale` за месяц (штуки, не деньги, не себестоимость);
- знаменатель `avgStock` = (opening + closing) / 2, где opening/closing — накопленное сальдо движений товара по всем складам
  (все типы, кроме `transfer_in/out`, которые дают 0) **начиная с 0 в начале запрошенного диапазона**; closing = opening + сальдо месяца;
- `turnover = unitsSold / avgStock` («раз за месяц», без приведения к году и без «дней оборота»);
- при `avgStock ≤ 0` строка не пишется. Записываются только товары, у которых в диапазоне было хотя бы одно движение; для них — все месяцы
  диапазона (в месяцах без продаж значение 0).

**(2) На каких данных.** Только на потоке `fetchStockMovements($period)` за запрошенный диапазон. `fetchStock` не используется;
сделки (`fetchDeals`, выручка) не используются вообще; цен/себестоимости в контракте нет.

**(3) Соответствие стандартной формуле.** Стандарт: оборачиваемость = себестоимость проданного (или выручка) / средний запас за период
(в деньгах), либо в натуральных единицах — продано штук / средний остаток штук; средний запас — среднее нескольких точек периода.
Здесь выбран натуральный вариант с двумя точками — это допустимая форма, **но остаток считается неверно**: сальдо от 0 в начале
диапазона — это не остаток, а накопленное изменение. Стартовый остаток (доступный теперь через `fetchStock(старт − 1)`) не учитывается,
поэтому avgStock занижен на величину этого остатка в каждом месяце, а turnover завышен либо строка теряется (сальдо ≤ 0).
Измерено на моке (Small/Medium, август 2026; «эталон» = продано / ((остаток на 31.07 + остаток на 31.08) / 2) по `fetchStock`):

| диапазон расчёта | строк за август (текущий / эталон) | текущее / эталон |
|---|---|---|
| Small, весь год (диапазон начинается с начала истории мока) | 50 (48 с продажами) / 48 | min 1.00, median 1.00, max 1.00 — совпадает |
| Small, один август | 22 / 48 | min 1.83, median 10.86, max 183.0 |
| Medium, дефолтные 12 месяцев (история мока 24 месяца) | 250 / 495 | n=247: min 1.00, median 4.57, max 296.0 |

То есть формула верна ровно тогда, когда диапазон начинается там, где остаток нулевой (начало истории); при любом другом начале
половина строк пропадает, а оставшиеся завышены в разы. Пример (Small, август): prod-3 продано 62, остаток 68 → 6, эталон 1.676,
расчёт за август 1.676 только в годовом диапазоне и **отсутствует** в месячном (сальдо −62 → avgStock < 0).
Дополнительно: (а) среднее по двум точкам не видит внутримесячных пиков (приход партии в середине месяца); (б) первый/последний
неполные месяцы диапазона не нормируются; (в) сделки и sale-движения в моке не согласованы (август, Small: 200 сделок на 53 089.40
против 3 528 проданных штук в движениях; корреляция числа сделок и проданных штук по товарам 0.11) — поэтому метрику
«выручка / средний остаток» на моке считать бессмысленно, а оборачиваемость по штукам — единственный корректный вариант;
(г) `metrics:calculate` с дефолтным периодом (A3) на Medium/Large попадает как раз в проблемный случай (12 месяцев из 24).

**(4) Проблемы и объём правки.**
- Проблема 1 (критичная): нулевой стартовый остаток. Проблема 2: два несогласованных представления остатка в коде
  (Turnover — относительное сальдо, DeadStock/DaysOfStock — `fetchStock`), из-за чего для одного товара метрики противоречат друг другу.
  Проблема 3 (умеренная): среднее по двум точкам. Проблема 4 (методическая): штуки, а не деньги — товары несопоставимы
  между собой; для денег нужны цена/себестоимость в контракте (сейчас их нет, это отдельное решение).
- **Вариант A (рекомендую): минимальная правка.** Сохранить сигнатуру `calculate(iterable $movements, DateRange)`, добавить
  необязательный аргумент `array $openingStock` (productId → остаток на `старт − 1`); `MetricsCalculationService` получает его одним
  вызовом `fetchStock(старт − 1)` (только если есть capability StockSnapshots, иначе поведение как сейчас + запись в лог). Файлы:
  `TurnoverCalculator`, `MetricsCalculationService`; тесты: `TurnoverCalculatorTest` (+3–4 случая с ненулевым стартовым остатком),
  `MetricsCalculationServiceTest` (счётчик вызовов), интеграционный тест на моке «один месяц == эталон по `fetchStock`» (по образцу
  `MockStockMetricsTest`). Оценка: 2 файла кода, 3 файла тестов, порядка 100–150 строк, риск низкий.
- **Вариант B: полная переработка** по образцу метрик остатков (калькулятор принимает адаптер, среднее по дням, `value_meta` с
  `units_sold`/`avg_stock`, нормирование неполных месяцев). Точнее, но: калькулятор читает адаптер сам (нарушает «один вызов» ещё
  сильнее), больше тестов и переписанные ожидания существующих. Имеет смысл только после решения о деньгах/себестоимости.
- Не менять `value` при этом нельзя: значения существующих снэпшотов turnover изменятся (исправятся) — пересчёт нужен.

## 8. Замеченные проблемы вне скоупа

- Турновер — раздел 7 (главная).
- `days_since_last_sale` теперь делает лишний вызов на `dead_stock_days` дней истории — на больших профилях стоимость выше (не измерялась).
- Расчёт всего пайплайна на Medium за год ≈ 28.6 с (мок симулирует историю заново на каждый вызов; `days_of_stock` — по два вызова на месяц).
- Старые снэпшоты с `no_sales_in_history` (если есть в БД) остаются до пересчёта месяца.
- `compare()`/`top()` фильтруют по `entity_type` уже после индекса; при появлении нескольких типов сущностей на один `metric_key`
  индекс стоит расширить (сейчас таблицу менять было запрещено).
- Ранжирование по дельте читает все строки периода (O(n), ~1 мс на 500 сущностей; на десятках тысяч будет заметнее).
- `WidgetDataProvider::kpiCard` округляет deltaPercent до 2 знаков, новый `MetricComparisonRow::$deltaPct` — нет (сознательно).
- `PeriodRange` и виджеты по-прежнему используют старый слой чтения (`MetricsSnapshotRepository`), новый слой запросов виджеты не использует.

## 9. Открытые вопросы

1. Turnover: делать Вариант A (стартовый остаток из `fetchStock`) сейчас отдельным этапом? Значения метрики при этом изменятся.
2. Нужна ли оборачиваемость в деньгах (тогда в контракт нужна цена/себестоимость)?
3. `deltaPct`: оставить без округления или округлять до 2 знаков, как в `kpiCard`?
4. Нужен ли индекс с `entity_type` (изменение таблицы, требует вашего разрешения) до появления второго типа сущностей на метрику?
5. Использовать `MetricsComparisonRepository` в виджетах (топ-таблицы/дельты) — отдельный этап?
