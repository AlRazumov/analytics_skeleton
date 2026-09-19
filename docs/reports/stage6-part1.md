# Этап 6, часть 1 — контракты и MockAdapter

Ветка `stage6-contracts` (от `master`), не запушена. Коммиты:

```
b567da2 feat(adapters): MockAdapter — детерминированная история движений, fetchStock, capabilities, манифест сценариев
c3c470f feat(core): StockMovement со знаком и новым enum типов, StockBalance, AdapterCapability, fetchStock/capabilities в DataSourceAdapter
d946921 feat(db): metrics_snapshots — period → period_type/period_start/period_end, канонические ключи периода в записях
68108a3 feat(core): value object Period (day/week/month/quarter/year), старый Period → PeriodRange
```

Коммит `c3c470f` сам по себе НЕ зелёный: `MockAdapter` в нём ещё использует старый
enum и не реализует новые методы контракта (чинится в `b567da2`). Отдельно этот коммит
я не запускал. Зелёность проверена на `68108a3`, `d946921` (полный прогон) и на HEAD.

## 1. Что сделано

- **1.3 Period.** Новый `App\Core\Domain\Period` (одна календарная «корзина»). Прежний
  класс `Period` в репозитории был другим понятием (диапазон дат + гранулярность Month/Day,
  `keys()`, `previousYear()`), поэтому он переименован в `PeriodRange`, а его `keys()`
  теперь отдаёт канонические ключи. `PeriodGranularity` расширен: day, week, month,
  quarter, year.
- **1.4 metrics_snapshots.** Миграция заменяет `period` на `period_type` / `period_start` /
  `period_end`, переносит существующие строки, добавляет уникальный индекс и индекс под топ.
  `MetricsSnapshotRecord::period` теперь хранит канонический ключ (`month:2026-02`);
  запись/чтение/удаление в БД переведены на тройку.
- **1.1 StockMovement.** Переделан: тип-enum (receipt, sale, transfer_in, transfer_out,
  writeoff, adjustment), количество со знаком, перемещение = две парные записи.
  Добавлен `StockBalance` (результат `fetchStock`).
- **1.2 DataSourceAdapter.** Добавлены `fetchStock(?DateTimeImmutable $asOf = null)` и
  `capabilities()`; enum `AdapterCapability` (StockMovements, StockSnapshots).
  `MetricsCalculationService` не вызывает `fetchStockMovements`, если capability нет.
- **2. MockAdapter.** Детерминированная история движений (seed + профиль + опциональные
  `MockScenarioConfig` и `historyEnd`), `fetchStock`, `capabilities`, шесть сценариев,
  `manifest()` → `MockScenarioManifest`.

## 2. Итоговые сигнатуры

```php
interface DataSourceAdapter
{
    /** @return iterable<Deal> */
    public function fetchDeals(DateRange $period): iterable;
    /** @return iterable<Product> */
    public function fetchProducts(): iterable;
    /** Границы DateRange включительно по дате; порядок не гарантирован. @return iterable<StockMovement> */
    public function fetchStockMovements(DateRange $period): iterable;
    /** Остаток на КОНЕЦ дня $asOf (время игнорируется); null — текущий. @return iterable<StockBalance> */
    public function fetchStock(?DateTimeImmutable $asOf = null): iterable;
    /** @return list<AdapterCapability> */
    public function capabilities(): array;
}

final readonly class StockMovement
{
    public function __construct(
        public string $id,            // внешний id записи (как Deal::$id / Product::$id)
        public string $productId,
        public string $warehouseId,
        public float $quantity,       // со знаком; ненулевое; знак проверяется по типу
        public StockMovementType $type,
        public DateTimeImmutable $date,
        public array $meta = [],
    ) {}   // InvalidArgumentException при нулевом/неверном по знаку quantity
}

enum StockMovementType: string
{
    case Receipt = 'receipt';         // +
    case Sale = 'sale';               // −
    case TransferIn = 'transfer_in';  // +
    case TransferOut = 'transfer_out';// −
    case Writeoff = 'writeoff';       // −
    case Adjustment = 'adjustment';   // любой знак
}

final readonly class StockBalance
{
    public function __construct(public string $productId, public string $warehouseId, public float $quantity) {}
}

enum AdapterCapability: string
{
    case StockMovements = 'stock_movements';
    case StockSnapshots = 'stock_snapshots';
}

enum PeriodGranularity: string { case Day = 'day'; case Week = 'week'; case Month = 'month'; case Quarter = 'quarter'; case Year = 'year'; }

final readonly class Period
{
    public PeriodGranularity $granularity;
    public DateTimeImmutable $start;   // 00:00, включительно
    public DateTimeImmutable $end;     // 00:00 последнего дня, ВКЛЮЧИТЕЛЬНО

    public function __construct(PeriodGranularity $granularity, DateTimeInterface $start, DateTimeInterface $end); // валидирует границы
    public static function containing(PeriodGranularity $granularity, DateTimeInterface $date): self;
    public static function fromKey(string $key): self;            // InvalidArgumentException на невалидном
    public function key(): string;                                // day:2026-08-19 | week:2026-W34 | month:2026-08 | quarter:2026-Q3 | year:2026
    public function previous(): self;
    public function yearAgo(): self;
    public function contains(DateTimeInterface $date): bool;
}

// Mock-слой (не core)
final class MockAdapter implements DataSourceAdapter
{
    public function __construct(
        MockDataProfile $profile = MockDataProfile::Medium,
        int $seed = 42,
        ?MockScenarioConfig $scenarios = null,   // по умолчанию $profile->scenarios()
        ?DateTimeImmutable $historyEnd = null,   // по умолчанию 2026-08-31
    );
    public function manifest(): MockScenarioManifest;
    public function historyStart(): DateTimeImmutable;
    public function historyEnd(): DateTimeImmutable;
}
```

`MockScenarioManifest` (публичные поля): `historyStart`, `historyEnd`, `seasonalProductIds`,
`seasonPeakMonth` (12), `seasonTroughMonth` (6), `deadProducts` (id → дата последнего
движения), `deadDays` (120), `nearZeroProducts` (id → warehouse_id, daily_rate, stock_at_end,
days_to_zero), `gapProducts` (id → окна from/to нулевого остатка), `spikeProducts`
(id → from/to/multiplier/baseline_daily), `hasTransfers`.

Профили (`MockDataProfile`): глубина истории Small 365 / Medium 730 / Large 730 дней;
сценарные товары (dead/near_zero/gaps/spike/seasonal): Small 2/2/2/2/4, Medium 5/5/5/5/25,
Large 10/10/10/10/100.

Договорённости по Period (в докблоке класса): конец включительный; недели ISO-8601
(понедельник, ключ по ISO-году); `yearAgo()` — день: та же календарная дата (29 фев → 28 фев),
неделя: та же ISO-неделя прошлого ISO-года (53-я → 52-я, если 53-й нет), остальные —
тот же месяц/квартал/год.

## 3. Схема metrics_snapshots после миграции

| колонка | тип |
|---|---|
| id | bigint PK |
| entity_type | varchar NOT NULL |
| entity_id | varchar NOT NULL |
| metric_key | varchar NOT NULL |
| value | numeric(20,4) NOT NULL |
| value_meta | jsonb NULL |
| period_type | varchar NOT NULL |
| period_start | date NOT NULL |
| period_end | date NOT NULL |
| created_at, updated_at | timestamp NULL |

Индексы: PK; `metrics_snapshots_entity_metric_period_unique` UNIQUE
(entity_type, entity_id, metric_key, period_type, period_start);
`metrics_snapshots_metric_period_value_index` (metric_key, period_type, period_start, value).
Старый индекс по `period` удалён вместе с колонкой.

Миграция `2026_09_19_100000_split_period_in_metrics_snapshots_table`: `up` переносит
`YYYY-MM` → month, `YYYY-MM-DD` → day (любой другой формат — исключение, миграция
прерывается); `down` возвращает старые строки, а week/quarter/year (в старой схеме их не
было) записывает каноническим ключом (`quarter:2026-Q3`), чтобы rollback не терял строки.
Применение и откат проверены тестом (с данными).

## 4. Расхождения с заданием и принятые решения

1. **Имя `Period` было занято.** Старый класс переименован в `PeriodRange` (4 файла
   мехнически + тест). Иначе новый value object пришлось бы назвать не так, как в задании.
2. **`fetchStock` в контракте вообще не было** (не «нет asOf», а нет метода), поэтому я
   его добавил и завёл DTO `StockBalance` — в задании такой сущности нет, но возвращать
   что-то нужно. Семантика asOf — «на конец дня», время игнорируется.
3. **Имена полей StockMovement.** Задание: `externalId`, `occurredAt`. Репозиторий: `id`,
   `date` (как у `Deal`, `Product`). Оставил репозиторные — вы разрешили придерживаться
   принятых соглашений об идентификаторах; `date` по той же логике (согласованность с
   `Deal::$date`). Количество — `float`, как везде в репозитории, а не decimal.
4. **Старый StockMovement сломан намеренно.** Было: In/Out/Transfer, беззнаковое
   количество, `toWarehouseId` у Transfer. Стало: 6 типов, количество со знаком, перемещение —
   пара записей (общий ключ в `meta['transfer_id']`). Иначе не выразить «остаток = сумма
   движений». Ломающие следствия: `TurnoverCalculator` и его тест, `MockAdapterTest`,
   `StockMovementTest`, `fakeAdapter()` в `tests/Pest.php`.
5. **`MetricsSnapshotRecord::period` — теперь канонический ключ** (`month:2026-02`), а не
   `2026-02`. Затронуты калькуляторы (Revenue, Abc, Xyz, Turnover ставят префикс `month:`
   прямо в коде — отдельного хелпера нет), тесты. В виджетах подписи остались прежними
   (`WidgetDataProvider::displayLabel()` отрезает префикс), чтобы UI не менялся.
6. **Уникальный индекс меняет поведение:** повторная запись той же (entity, metric, period)
   теперь падает, а не дублирует строку. `metrics:calculate` это не задевает (он удаляет месяцы
   диапазона перед записью) — проверил прогоном, включая повторный запуск и бэкфилл.
7. **Окно истории у MockAdapter фиксировано** (`historyEnd` = 2026-08-31 по умолчанию, не
   «сегодня»), иначе seed не давал бы одинаковых данных в разные дни. Вне окна движений нет.
8. **Порядок движений** — по товарам, внутри товара по времени (не глобально по дате).
   Записал в контракте «порядок не гарантирован».
9. **DateRange для движений в моке — включительно по дате** (старый мок трактовал конец
   как момент 00:00).
10. **Small-профиль теперь на 2 склада** (было 1) — иначе сценарий перемещений не существует
    в Small. Старый тест «Small без Transfer» удалён как противоречащий заданию.
11. **Удалено из мока:** старый «дисбаланс-сценарий» (Large: приход 5000 на одном складе и
    расход 4800 на другом) — он приводит к отрицательным остаткам, что запрещено заданием;
    и классы `SingleSpikeAnomaly` / `DemandAnomaly`, ставшие мёртвым кодом (их роль играет
    сценарий 5). Если дисбаланс нужен будущей метрике, его надо задавать иначе.
12. **`MetricsCalculationService`** теперь проверяет capability `StockMovements` (в задании
    прямо не просилось, но иначе «спросить вместо падения» нечем воспользоваться).
    Добавлен тест.
13. **Сделки (`fetchDeals`) не тронуты** и по-прежнему генерируются независимо от движений.

## 5. Что было в репозитории до правок и что изменено

Было: `DataSourceAdapter` с `fetchDeals/fetchProducts/fetchStockMovements`; `MockAdapter` со
случайными потоками, зависящими от запрошенного диапазона (без согласованности с остатками);
`Period` (диапазон Month/Day); `StockMovement` In/Out/Transfer; `metrics_snapshots` с
строковым `period` и обычным индексом; 50 тестов. `docs/roadmap.md`: этапы 00–05 done,
06/07 — Bitrix24/OneC «not planned».

Изменено в существующем коде (не считая новых файлов): `Period` → `PeriodRange`;
`WidgetDataProvider`, `OverviewDashboardController`, `DemoWidgetsController` (переименование
типа, подписи); `Revenue/Abc/Xyz/TurnoverCalculator` (ключи периода; Turnover — знаковая
арифметика); `MetricsCalculationService` (capability); `CalculateMetrics::deleteExistingSnapshots`
(по period_type/period_start); модель `MetricsSnapshot`, `EloquentMetricsSnapshotRepository`,
`EloquentMetricsSnapshotWriter`; `MockDataProfile`, `MockAdapter`; тесты (`Pest.php`,
`WidgetDataProviderTest`, `Abc/Xyz/Revenue/Turnover/MetricsCalculationServiceTest`,
`EloquentMetricsSnapshotRepositoryTest`, `StockMovementTest`, `MockAdapterTest`).
Смысл `TurnoverCalculator` сохранён, кроме того, что списания и корректировки теперь
меняют сальдо (раньше таких типов не было).

## 6. Результат запуска тестов

Хостовый PHP 8.3.6, проекту нужен ≥ 8.4.1, поэтому тесты идут в контейнере Sail
(`docker compose up -d`, затем `exec`).

База до правок: `docker compose exec -T laravel.test php artisan test` → `50 passed (5822 assertions)`.

Итог на HEAD (`b567da2`):

```
$ docker compose exec -T laravel.test php artisan test
Tests:    139 passed (72424 assertions)
Duration: 7.78s
```

`vendor/bin/pint --test` → PASS, 91 файл.

Дополнительно, вне набора тестов:
- `metrics:calculate --profile=small` (дважды подряд) и `--period=2025-12:2026-02` отработали
  без ошибок (1003 / 1003 / 270 снэпшотов, включая turnover).
- Разовый скрипт (не тест, в репозиторий не добавлен) на Large-профиле, вся история:
  2 493 963 движения, 25 000 строк остатков, `fetchStock` == сумма движений (0 расхождений),
  минимальный остаток по паре товар×склад = 5 (отрицательных нет), пик памяти 8.5 МБ,
  но ~36 секунд. Тесты используют Small/Medium; Large в тестах не прогоняется.

Что НЕ проверялось: коммит `c3c470f` отдельно; Large-профиль внутри Pest-набора; работа
на хостовом PHP (не запускается из-за версии).

## 7. Замеченные проблемы и риски вне скоупа

- **Сделки и движения не согласованы** между собой (продажи в `fetchDeals` не равны
  `sale`-движениям). Метрикам, сопоставляющим выручку и остатки, мок этого не даст.
- **`TurnoverCalculator` по-прежнему считает «относительный остаток от 0»**, хотя теперь
  есть `fetchStock`; перевод на абсолютные остатки не делал (это метрика).
- **`staging_stock_movements`** остался со старой схемой (`to_warehouse_external_id`,
  беззнаковое `quantity`, старые значения `type`); модель `StagingStockMovement` не менял.
  Когда появится реальный синк, схему надо привести к новому `StockMovement`.
- **Default-период `metrics:calculate`** — «последние 12 месяцев от сегодня»; окно истории
  мока заканчивается 2026-08-31, поэтому последние месяцы будут пустыми по движениям.
- **Large-профиль тяжёлый** для полного прогона (десятки секунд, миллионы записей); любое
  чтение начинает симуляцию с первого дня истории.
- **Префикс `'month:'`** захардкожен в четырёх калькуляторах; при появлении других
  гранулярностей в метриках лучше выдавать `Period`.
- **Dev-БД `laravel` сброшена** мной командой `migrate:fresh` при ручной проверке (без
  предварительного вопроса). Содержимое было воспроизводимым выводом `metrics:calculate`
  на моке, но я не проверял, что там не было ничего ещё; впредь надо было использовать
  отдельную БД.

## 8. Открытые вопросы

1. **Нумерация этапа.** В `docs/roadmap.md` этап 06 = Bitrix24Adapter (not planned), а вы
   называете эту работу «этап 6». По правилам CLAUDE.md новая работа — новая строка в
   roadmap и `stage-NN-*.md`; я roadmap и CLAUDE.md НЕ трогал. Под каким номером её
   оформить (например, 08)?
2. Устраивает ли оставить `id`/`date` вместо `externalId`/`occurredAt` (п. 4.3)?
3. Нужен ли дисбаланс-сценарий межскладских остатков в новом виде (п. 4.11)?
4. Нужен ли `fetchStock` для сравнения периодов (несколько дат за прогон), или достаточно
   одной даты?
5. Оставить ли `PeriodRange` как есть или перевести виджеты на `Period` + список?
