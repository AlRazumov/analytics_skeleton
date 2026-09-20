# Отчёт по этапу 14: рекомендации перемещений между складами

Ветка `stage-14-transfer-recommendations` (от `stage-13-mock-realism`, она не слита в `master`). Не пушилось, не мержилось. Миграций нет, dev-БД не использовалась, тесты — только в Sail на БД `testing`.

## 1. Что сделано

Коммиты (по порядку): docs (roadmap + stage-файл) → A → B → C → D.

- **A. Планировщик.** `App\Core\Transfers\TransferPlanner` (+ `TransferPosition`, `TransferRecommendation`, `TransferPlan`) — чистый PHP, без БД/Laravel. Unit-тесты: ручные наборы по всем пунктам задания + property-тест на 200 наборах (seed `20260920`).
- **B. Чтение и сервис.** Метод `rowsOfProductsWithValueAtMost()` в `MetricsComparisonRepository` (интерфейс в core, реализация в `app/Repositories`), `App\Services\TransferRecommendationService::forPeriod()`, конфиг `analytics.transfers`, флаг `analytics.features.transfers`. Тесты на PostgreSQL, включая фиксацию ограничения v1.
- **C. Страница.** `/dashboards/transfers` (`dashboards.transfers`), `auth` + `feature:transfers`, пункт «Перемещения» в навигации по флагу, сводка, таблица, пояснение правил. Провайдер `App\Services\TransferTableProvider`, DTO `TransferTableData`/`TransferRow` в `app/Core/Widgets/DTO`, Blade-компонент `x-widgets.transfers-table`. Тесты страницы, N+1.
- **D. Эталон на моке.** `tests/Feature/Transfers/TransfersOnMockTest.php` (Small — быстрый, Medium — `slow`), метрики через `demo:install` (реальные калькуляторы за всю историю мока). Страница добавлена в `DemoIntegrityTest` (5 → 6 страниц). Обновлены `docs/features.md`, `docs/demo.md`; `docs/security.md` не менялся.

## 2. Разведка (до правок)

- **`MetricsComparisonRepository`** (`app/Core/Widgets/Contracts/`, реализация `app/Repositories/EloquentMetricsComparisonRepository.php`): `compare()` (поток по `cursor()`, сортировка `entity_id COLLATE "C"`), `top()`/`count()` (фильтр по value ТЕКУЩЕГО периода, `value_meta` в строках), `latestPeriod()`, `bucketCounts()`. Период/тип/метрика фильтруются по `period_type` + `period_start`. Интерфейс лежит в `Core\Widgets\Contracts`, а не в `Core\Contracts`.
- **`ProductTablesProvider`** (`app/Core/Widgets`): период — явный или `latestPeriod(метрика, Month)`; нет периода → `RankedTableData(null, [], 0)`; названия — один вызов резолвера на виджет; fallback на id; пороги/лимиты — аргументами. `stockoutRisk()` — ближайший аналог, но берёт только top-N пар и **не** отдаёт все пары товара, поэтому для планировщика непригоден.
- **Выборка `days_of_stock` за период с `value_meta`:** готового метода «все пары товаров с дефицитной парой» не было (`top()` ограничен 1000 строк и одним фильтром по value) → добавлен новый метод.
- **Период и флаги на страницах:** трейт `ResolvesMonthPeriod::requestedMonth()` (нет `?period` → null; невалидный/не месяц → 404); контроллеры берут `latestPeriod` метрики через провайдер; флаги — middleware `feature:<имя>` (404), пункты навигации — `config('analytics.features.*')` в `standalone.blade.php`.
- **Данные мока:** `days_of_stock` не пишется для пар без спроса (см. п. 9).

## 3. Правила алгоритма как реализованы

- Покрытие = `stock / dailyRate`. Игнор: `dailyRate <= 0`, `stock < 0`.
- Дефицит: покрытие `<= deficit_days` (включительно). Донор: покрытие `>= surplus_days` и `stock - rate*keep_days > 0`.
- Валидация конструктора: `deficit < target <= keep <= surplus`, `min_quantity > 0` → `InvalidArgumentException`.
- Потребность `rate*target - stock`; доступно `stock - rate*keep`.
- Порядок: по товару отдельно; дефициты по покрытию ↑, tie-break `warehouseId` (`strcmp`); доноры для каждого дефицита — по оставшемуся доступному ↓, tie-break `warehouseId` (`strcmp`).
- Округление: `floor(min(остаток потребности, доступно))`; строка `< min_quantity` отбрасывается, донор на неё не тратится. Остаток потребности и доступное уменьшаются на **округлённое** количество.
- «Покрытие после» — нарастающим итогом и у получателя, и у донора (задание требовало это только для получателя; для донора сделано так же, иначе инвариант «донор не ниже keep_days» не проверялся бы при нескольких получателях). Итог считается в порядке распределения, а строки выводятся в заданном порядке (toCoverageBefore ↑, productId, toWarehouseId, fromWarehouseId), поэтому у одного получателя с несколькими донорами «после» в выведенных строках не обязательно возрастает (зафиксировано в докблоке и тесте).
- Сравнение идентификаторов — `strcmp` (побайтово), а не числовое сравнение строк.

**Расхождения/уточнения к заданию:**
1. `TransferPlan` кроме `recommendations` и `unmatchedDeficits` содержит `deficitPairs` — сервису и странице нужно число дефицитных пар, а дублировать правило дефицита в сервисе не хотелось.
2. «Дефицит без рекомендации» (`unmatchedDeficits`) включает и случай, когда все возможные строки отброшены по `min_quantity`, а не только «нет донора».
3. `TransferTableProvider` лежит в `app/Services`, а не в `app/Core/Widgets`: он использует прикладной сервис, а core от app зависеть не должен. DTO — в `Core/Widgets/DTO`.
4. `deficitPairs` считает только пары, у которых есть `value_meta` (строка без меты пропускается и попадает в `skippedWithoutMeta`).

## 4. Сигнатуры

```php
final class TransferPlanner {
    public function __construct(float $deficitDays, float $targetDays, float $keepDays, float $surplusDays, int $minQuantity);
    /** @param iterable<TransferPosition> $positions */
    public function plan(iterable $positions): TransferPlan;
}
final readonly class TransferPosition { string $productId; string $warehouseId; float $stock; float $dailyRate; }
final readonly class TransferRecommendation {
    string $productId; string $fromWarehouseId; string $toWarehouseId; int $quantity;
    float $fromCoverageBefore; float $fromCoverageAfter; float $toCoverageBefore; float $toCoverageAfter; float $toDailyRate;
}
final readonly class TransferPlan { array $recommendations; int $deficitPairs; int $unmatchedDeficits; }

// MetricsComparisonRepository (+ EloquentMetricsComparisonRepository)
/** @return iterable<MetricComparisonRow> */
public function rowsOfProductsWithValueAtMost(string $metricKey, string $entityType, Period $period, float $maxValue): iterable;

final class TransferRecommendationService {
    public function forPeriod(Period $period): TransferRecommendationResult; // plan, positionsRead, skippedWithoutMeta
}
final readonly class TransferTableProvider { public function forPeriod(?Period $period, int $limit): TransferTableData; }
```

Конфиг (`config/analytics.php`):

```php
'transfers' => ['deficit_days' => 14, 'target_days' => 30, 'keep_days' => 30, 'surplus_days' => 60, 'min_quantity' => 1],
'features' => [..., 'transfers' => (bool) env('ANALYTICS_FEATURE_TRANSFERS', true)],
```

SQL выборки — один запрос: `... WHERE <метрика/тип/период> AND split_part(cur.entity_id, ':', 1) IN (SELECT split_part(entity_id, ':', 1) FROM metrics_snapshots WHERE <те же условия> AND value <= ?) ORDER BY entity_id COLLATE "C"`, чтение через `cursor()`. Тест считает запросы (`DB::listen`) = 1. Ключ разбирается только через `ProductWarehouseKey::parse`. Замечание: сам планировщик группирует позиции по товарам в памяти, поэтому потоковость касается чтения из БД, а не всего пайплайна.

## 5. Числа на моке (seed 42, последний месяц истории, метрики через `demo:install`)

Формат: покрытие «откуда» до→после, «куда» до→после (дни). Оракул (в тесте, считается от чисел манифеста, планировщик не вызывается) совпал с планировщиком по количеству и всем четырём покрытиям во всех строках.

**Small** (дефицитных пар 6, без рекомендации 4, всего рекомендаций 2, прочитано позиций 8):

| Товар | Откуда → куда | Кол-во (план / оракул) | Откуда | Куда |
|---|---|---|---|---|
| prod-13 | wh-1 → wh-2 | 90 / 90 | 120 → 30 | 5 → 27,5 |
| prod-14 | wh-2 → wh-1 | 120 / 120 | 150 → 90 | 6 → 30 |

Лишних (не из манифеста) рекомендаций: **0**.

**Medium** (дефицитных пар 18, без рекомендации 10, всего рекомендаций 8, прочитано позиций 29):

| Товар | Откуда → куда | Кол-во (план / оракул) | Откуда | Куда |
|---|---|---|---|---|
| prod-46 | wh-1 → wh-2 | 90 / 90 | 120 → 30 | 5 → 27,5 |
| prod-47 | wh-2 → wh-3 | 120 / 120 | 150 → 90 | 6 → 30 |
| prod-48 | wh-3 → wh-1 | 138 / 138 | 180 → 42 | 7 → 30 |
| prod-49 | wh-1 → wh-2 | 88 / 88 | 210 → 166 | 8 → 30 |
| prod-50 | wh-2 → wh-3 | 90 / 90 | 120 → 30 | 9 → 27 |

Лишних (не из манифеста) рекомендаций: **3**. Их происхождение я не разбирал (информационное число, не ошибка по заданию).

Где «куда» не доходит до 30 дней (prod-13, prod-50, prod-46) — ограничивает донор (доступно меньше потребности), а не округление.

**nearZero:** по данным у каждого nearZero-товара (Small — 2, Medium — 5) ровно одна строка `days_of_stock` в последнем месяце, т.е. второго склада с остатком нет; рекомендаций нет. Ожидание не менялось (тест проверяет и «одна строка», и «нет рекомендаций»).

## 6. Изменённые существующие тесты

- `tests/Feature/FeatureMiddlewareTest.php` («has all flags on by default»): в ожидаемый массив флагов добавлен `'transfers' => true` — новый флаг по умолчанию включён.
- `tests/Feature/Dashboards/StockAndTopPagesTest.php` (тест N+1): добавлена страница `/dashboards/transfers` и доноры (склад `w2` с большим запасом) — по заданию «расширь этой страницей».
- `tests/Feature/DemoIntegrityTest.php`: `/dashboards/transfers` добавлена в `DEMO_PAGES` (страница с данными после `demo:install`, 0 canvas, непустые строки, рендер при бросающем адаптере).

## 7. Результат тестов

Все команды — `./vendor/bin/sail artisan test ...`, тестовая БД `testing`.

- Коммит A (`--exclude-group=slow`): `tests 411, passed 411, assertions 77850`, ~58 с. Unit планировщика: `tests 18, passed 18, assertions 1433`.
- Коммит B: при первом прогоне упал `FeatureMiddlewareTest::it has all flags on by default` (`Failed asserting that two arrays are identical`, добавился `transfers`). Я ошибочно закоммитил до проверки результата, затем поправил тест и сделал `git commit --amend` (локальный, не запушенный коммит). Итоговый прогон: `tests 418, passed 418, assertions 77869`.
- Коммит C (`--exclude-group=slow`): `tests 428, passed 428, assertions 77919`. Тесты страницы: `tests 10, passed 10`.
- Коммит D:
  - полный прогон: `tests 440, passed 440, assertions 95355`, **136 с** (прогон в фоне, до финального Pint-исправления форматирования в тесте);
  - `--exclude-group=slow`: `tests 429, passed 429, assertions 77960`, **~59 с**;
  - `Transfers/TransfersOnMockTest` (Small + Medium): passed, ~45 с.
- Pint: `{"tool":"pint","result":"passed"}` (`pint --test` по всему проекту). Замечание: после последних правок тестов D Pint сам поправил импорты в `TransfersOnMockTest.php`; полный прогон 440 запускался в том же вызове, что и этот автоисправляющий Pint, а `--exclude-group=slow` (429) и `pint --test` прогонялись уже после.

## 8. Что проверить глазами

Страницу я не видел, ничего не утверждаю про внешний вид. Смотрите на:
1. `/dashboards/transfers` после `demo:install` на Small: две строки (Product 13/14, названия складов из справочника), сводка 6 / 2 / 4.
2. Читаемость длинных заголовков колонок «Покрытие … (до → после)» и вёрстку таблицы из 7 колонок на узком экране (стили я не менял, используется общий `table`).
3. Что стрелка «→» и формат чисел (`27,5`, пробелы в тысячах) смотрятся нормально.
4. Пояснение под таблицей: тексты про пороги и «не учитывает сроки доставки и сезонность».
5. Пункт «Перемещения» в навигации: не переполняется ли шапка (теперь 6 пунктов).
6. `?period=month:2026-07` и несуществующий период (должно быть «Нет данных за период»); переключателя периода на странице нет.
7. Пустая БД (метрики не считались): «Нет данных: расчёт метрики ещё не выполнялся».

## 9. Замеченные проблемы вне скоупа

- **Ограничение v1 (Known issue):** склад с остатком, но без продаж, донором не считается — метрика `days_of_stock` не пишется для пар без спроса (`no_demand`), поэтому строки для такого склада нет. Пример: дефицит на `w1`, на `w2` большой остаток и нулевые продажи → рекомендаций нет, `unmatched = 1`. Зафиксировано тестом (`TransferRecommendationServiceTest`, «known limitation of v1») и в докблоке сервиса. Реальный кейс «залежавшийся товар на втором складе» этим планировщиком не покрывается; решение потребовало бы читать остатки (`fetchStock`) или писать иную метрику — вне скоупа этапа.
- Запись об этом ограничении добавлена в Known issues `docs/roadmap.md`.
- Планировщик не различает «дефицитную пару» и «пару, по которой нет данных за окно» (метрика не пишется при `in_stock_days < min_in_stock_days`) — такие пары не видны вообще.
- Не проверял, как ведёт себя страница при очень больших выборках (Large-профиль не запускался).

## 10. Открытые вопросы

1. Нужен ли переключатель периода/ссылки «предыдущий месяц» на странице? Сейчас только `?period=`, как на остальных страницах.
2. Устраивает ли, что «покрытие после» донора считается нарастающим итогом (в задании это было прописано только для получателя)?
3. Разобрать ли происхождение 3 «лишних» рекомендаций на Medium? Скорее всего, это пары товаров с несколькими складами из других сценариев, но я этого не проверял.
4. Нужно ли учитывать ограничение v1 (склады без продаж как доноры) до появления реальных адаптеров или можно оставить как есть?
