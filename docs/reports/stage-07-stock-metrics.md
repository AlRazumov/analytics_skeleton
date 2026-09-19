# Этап 07 — метрики остатков: неликвиды и дни до обнуления

Ветка `stage-stock-metrics` (от `stage6-contracts`), не запушена, не смержена.
Dev-БД `laravel` не использовалась: все тесты идут на тестовой БД `testing`
(`phpunit.xml`) в Sail; разрушительных команд не выполнялось.

## 1. Что сделано

**Шаг 0 (подготовка).**
- Резервная ветка `backup/stage6-contracts` — точная копия прежнего состояния `stage6-contracts`.
- Коммиты `c3c470f` и `b567da2` склеены в `c27ebe6` (через cherry-pick на отдельной временной ветке;
  дерево итогового коммита побайтно совпало с прежней вершиной — проверено `git diff`).
  `stage6-contracts` переставлена на новую историю (не запушена), временная ветка удалена.
- Тесты прогнаны на каждом коммите ветки (раздел 5).
- Ветка `stage-stock-metrics` создана от обновлённой `stage6-contracts`.

**Проверка покрытия сценариев мока.** dead / nearZero / gaps уже проверялись по данным
(а не только по манифесту). Для сценария 5 (всплеск) проверялись только первый и последний
день окна и соседние дни — добавлен тест по всем дням окна с допуском ±10 %, по среднему по
окну (> 0.9 × baseline × multiplier) и по отношению к средним продажам вне окна (> 5×).

**Метрики** (паттерн существующих калькуляторов: класс в `core/Analytics`, возвращает
`MetricsSnapshotRecord[]`, подключён в `MetricsCalculationService` и в `metrics:calculate`):
- `days_since_last_sale` — `DeadStockCalculator`;
- `days_of_stock` — `DaysOfStockCalculator`;
- `ProductWarehouseKey` — единственное место формата `<productId>:<warehouseId>`;
- `Months` — перечисление календарных месяцев диапазона;
- `config/analytics.php` — пороги (`dead_stock_days`=90, `days_of_stock_window`=28, `min_in_stock_days`=7),
  в калькуляторы передаются конструктором из `AppServiceProvider` (core о Laravel-конфиге не знает);
- `MetricsCalculationService`: проверка capabilities, логирование причины (PSR-3 `LoggerInterface`);
- `CalculateMetrics`: удаление перед записью расширено на новые пары (entity_type, metric_key).

**Roadmap.** Этап 07 — эти метрики; Bitrix24Adapter → 08, OneCAdapter → 09 (оба отложены).
Добавлен `docs/stages/stage-07-stock-metrics.md`; обновлены roadmap, `CLAUDE.md` (ссылка «этапы 08/09»),
примечание в `stage-06-contracts-and-mock.md`.

## 2. Сигнатуры и формат данных

```php
final class DeadStockCalculator
{
    public const ENTITY_TYPE = 'product';
    public const METRIC_KEY = 'days_since_last_sale';
    public function __construct(int $thresholdDays = 90);
    /** @return MetricsSnapshotRecord[] */
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array;
}

final class DaysOfStockCalculator
{
    public const ENTITY_TYPE = 'product_warehouse';   // = ProductWarehouseKey::ENTITY_TYPE
    public const METRIC_KEY = 'days_of_stock';
    /** @var array{no_demand: int, too_few_in_stock_days: int} пропуски последнего calculate() */
    public array $lastSkipped;
    public function __construct(int $windowDays = 28, int $minInStockDays = 7);
    public function calculate(DataSourceAdapter $adapter, DateRange $period): array;
}

final class ProductWarehouseKey
{
    public const ENTITY_TYPE = 'product_warehouse';
    public static function make(string $productId, string $warehouseId): string;   // "prod-1:wh-2"; ':' в id запрещён
    public static function parse(string $key): array;                              // [productId, warehouseId]
}

final class MetricsCalculationService
{
    public function __construct(
        RevenueByPeriodCalculator $revenue = ..., AbcClassifier $abc = ..., XyzClassifier $xyz = ...,
        TurnoverCalculator $turnover = ..., DeadStockCalculator $deadStock = new DeadStockCalculator,
        DaysOfStockCalculator $daysOfStock = new DaysOfStockCalculator, LoggerInterface $logger = new NullLogger,
    );
}
```

Формат снэпшотов (period_type = month, period = `month:YYYY-MM`):

| metric_key | entity_type | entity_id | value | value_meta |
|---|---|---|---|---|
| `days_since_last_sale` | `product` | `prod-1` | дней от последней продажи до asOf | `{"stock_qty": 95.0, "threshold_days": 90}`; при отсутствии продаж в диапазоне добавляется `"no_sales_in_history": true` |
| `days_of_stock` | `product_warehouse` | `prod-1:wh-2` | остаток на asOf / скорость продаж; 0 при нулевом остатке | `{"stock_qty": 44.0, "daily_rate": 2.0, "in_stock_days": 28, "window_days": 28}` |

Флага «неликвид» в БД нет: отбор — `value >= threshold`.

## 3. Формулы как реализованы

**asOf.** Для месяца M: `asOf = min(последний день M, DateRange::end)`. «Не позже historyEnd
источника» реализовано только как «не позже переданной границы»: контракт `DataSourceAdapter` не
сообщает, где кончается история источника, а core не должен знать про mock (см. п. 4.2).
Внутри метрик нет `now()`; результат зависит только от адаптера и диапазона.

**Неликвиды.**
- Остаток на asOf = `fetchStock(начало диапазона − 1 день)` + сумма движений диапазона (все типы), по всем
  складам. Снэпшот пишется для товаров с остатком > 1e-9.
- Последняя продажа — максимальная дата `sale` (любой склад) не позже asOf; `receipt`, `transfer_*`,
  `writeoff`, `adjustment` продажей не считаются.
- Нет продажи в диапазоне до asOf → value = дней от начала диапазона до asOf + `no_sales_in_history`.
- Движения читаются одним потоком; в памяти сумма движений и дата последней продажи по паре (товар, месяц).

**Дни до обнуления.** Окно W дней, заканчивающееся на asOf. Остаток на начало окна —
`fetchStock(начало окна − 1 день)` (вызов на каждый месяц); движения окна — отдельный вызов
`fetchStockMovements` на каждый месяц. Для пары товар×склад: остаток по дням восстанавливается
последовательным применением суточных сумм движений (все типы); день считается «в наличии», если
остаток на НАЧАЛО дня > 0; скорость = сумма продаж окна / число дней в наличии; значение = остаток на
asOf / скорость. Пара пропускается (записи нет), если продаж нет, или дней в наличии < `min_in_stock_days`.
Пары, в которых продаж в окне нет, а остаток есть, считаются в `lastSkipped['no_demand']` (счётчик приблизительный:
остаток берётся на начало окна). Счётчики логируются уровнем info.

Расхождение с заданием — п. 4.3 (сдвиг «день прихода») и п. 4.4 (много вызовов адаптера).

## 4. Расхождения с заданием и принятые решения

1. **Логирования при отсутствии StockMovements раньше не было.** В задании сказано «как уже сделано» — на самом деле
   прежний код (этап 06) просто не вызывал `fetchStockMovements` без capability, без записи в лог. Логирование
   добавлено сейчас для обеих метрик и для turnover (`warning`); покрыто тестом на spy-логгере.
2. **historyEnd источника.** См. п. 3: реализована только граница диапазона. Побочный эффект: если диапазон заходит за
   конец истории источника, неликвиды в «пустых» месяцах растут с календарём, а `days_of_stock` там не пишется (нет продаж).
   Дефолтный период `metrics:calculate` (12 месяцев от сегодняшней даты) заходит за конец истории мока (2026-08-31).
3. **День прихода.** Определение из задания («остаток на начало дня > 0») даёт смещение: в день, когда утром пришла
   партия, остаток на начало дня 0 — день не попадает в знаменатель, а продажи этого дня — в числитель. Сразу после провала
   скорость завышена (цифры в п. 6). Реализовано ровно как в задании; закреплено в докблоке; вопрос к вам — п. 8.
4. **Новые калькуляторы принимают `DataSourceAdapter`, а не готовые данные, и читают адаптер сами** (в отличие от
   Revenue/Abc/Xyz/Turnover): им нужны `fetchStock` на разные даты и окна движений по месяцам. Следствие: за один
   `calculate()` `fetchStockMovements` вызывается 1 раз (turnover) + 1 раз (неликвиды) + по разу на месяц (дни до обнуления).
   Регрессионный тест «ровно один вызов» теперь гоняется на адаптере только с capability StockMovements (метрики остатков
   в нём не участвуют) — это изменение существующего теста, с комментарием. Докблок `MetricsCalculationService` про
   «один вызов» остался прежним и для этих метрик неточен (вне скоупа, см. п. 7).
5. **Остаток для неликвидов** считается один раз через `fetchStock(старт − 1)` + движения по месяцам, а не `fetchStock` на каждый
   месяц (это дешевле и следует принципу «остаток на начало окна — через fetchStock»).
6. **`CalculateMetrics::deleteExistingSnapshots` расширен.** Он удалял только `entity_type='product'`; без расширения
   повторный прогон упал бы на уникальном индексе для `product_warehouse`. Проверено тестом идемпотентности.
7. **Диапазон для неликвидов определяет «доступную историю».** Продажи до начала диапазона метрика не видит;
   в докблоке: брать диапазон не короче порога.
8. **Значения ключа `product_warehouse`**: `ProductWarehouseKey::make()` бросает исключение, если id содержит `:`.

## 5. Результаты тестов

Команда: `docker compose exec -T laravel.test php artisan test` (Sail; хост-PHP 8.3 не подходит).

Коммиты после склейки (проверены последовательным checkout каждого):

```
68108a3 feat(core): value object Period ...                          Tests: 104 passed (5901 assertions)
d946921 feat(db): metrics_snapshots — period → period_type ...       Tests: 108 passed (5916 assertions)
c27ebe6 feat(core,adapters): StockMovement со знаком ... (squash)    Tests: 139 passed (72424 assertions)
4c60690 docs: отчёт по этапу 6, часть 1                              Tests: 139 passed (72424 assertions)
7491d65 docs: этап 06 = контракты и мок ...                          Tests: 139 passed (72424 assertions)
3f6db49 test(mock): сценарий 5 — все дни окна всплеска               Tests: 140 passed (72456 assertions)
4478ad3 feat(metrics): days_since_last_sale и days_of_stock          Tests: 171 passed (72665 assertions)
c7b523f docs: этап 07 в roadmap, адаптеры на 08/09                   Tests: 171 passed (72665 assertions)
```

Итог на HEAD ветки `stage-stock-metrics` (после коммита отчёта код не менялся):

```
$ docker compose exec -T laravel.test php artisan test
Tests:    171 passed (72665 assertions)
Duration: 13.53s
$ docker compose exec -T laravel.test vendor/bin/pint --test
PASS   100 files
```

Новые тесты: `DeadStockCalculatorTest` (8), `DaysOfStockCalculatorTest` (9), `MockStockMetricsTest` (7 тестов, 12 с датасетами),
`StockMetricsCommandTest` (2, Feature), плюс тест сценария 5 и тест capability/логирования в
`MetricsCalculationServiceTest`.

## 6. Ложные срабатывания неликвидов и отклонения от эталона

**Неликвиды (asOf = 2026-08-31, порог 90):**
- Small, seeds 1, 2, 3: флаги `value >= 90` совпали ровно с `deadProducts` манифеста (2 из 2), ложных срабатываний 0
  (в тесте закреплено).
- Medium, seed 1 (разовая проверка скриптом, не тест): 5 из 5 мёртвых, ложных 0; из 500 товаров с остатком.
- Large не проверялось.

Почему ложных нет (а не «подогнано»): обычные товары продаются в 60 % дней и пополняются по точке заказа; провалы по остатку
у сценарных `gaps` короче 90 дней (21). Данные мока не менялись.

**Дни до обнуления, эталон nearZero:** совпадение точное (Small и Medium): value = `days_to_zero`, daily_rate = `daily_rate`,
in_stock_days = 28. Допуск в тесте 1e-6: у сценария нет шума и сезонности (ровно daily_rate в день, окно целиком с остатком).
Для обычных товаров с шумом и сезонностью допуск был бы иным — они в эталоне не участвуют.

**Провалы (gaps), окно 28 дней, asOf = конец провала + 8 дней** (Small, seed 1; 2 товара × 3 провала, значения по товарам
идентичны):

| провал / asOf | baseline (продаж/день) | корректная скорость | наивная (продажи/28) | days_of_stock корректный | days_of_stock наивный | «истинный» (остаток/baseline) |
|---|---|---|---|---|---|---|
| 2025-12-29 | 3.00 | 3.429 | 0.857 | 54.25 | 217.0 | 62.0 |
| 2026-03-30 | 3.00 | 3.429 | 0.857 | 54.25 | 217.0 | 62.0 |
| 2026-06-29 | 3.00 | 3.429 | 0.857 | 63.88 | 255.5 | 73.0 |

Корректный вариант заметно ближе к baseline (+14 % против −71 %); наивный занижает скорость и завышает дни до обнуления
в 3.5–4 раза. Остаточное +14 % — сдвиг «день прихода» (п. 4.3: 8 дней продаж / 7 дней в наличии).
Почему +8, а не +7: при +7 в окне только 6 дней с остатком на начало дня, и метрика по правилу не пишется
(это проверено отдельным тестом).

Объём на моке: Small, весь год — 596 строк неликвидов (0.1 с); Medium, вся история — 11 995 строк (2.2 с);
`days_of_stock` Small, один месяц — 90 строк (0.11 с). Прочие масштабы не измерялись.

## 7. Замеченные проблемы вне скоупа

- **Стоимость на больших профилях.** Каждый вызов `fetchStock`/`fetchStockMovements` мока симулирует историю с первого дня;
  `days_of_stock` делает по два вызова на месяц. Измерено ранее: полный проход Large (движения + остатки) ≈ 36 с; оценка
  (не измерялась) для 12–24 месяцев Large — порядка десятков минут. Для реальных адаптеров с фильтрацией по дате это
  не так критично, но нагрузка «вызов на месяц» — осознанная цена подхода.
- **Дефолтный период `metrics:calculate`** заходит за конец истории мока (п. 4.2).
- **Докблок `MetricsCalculationService`** («ровно по одному разу») теперь неточен для метрик остатков.
- **Дублирование** перечисления месяцев: в Xyz/Turnover/Command есть собственные копии; `Months` появился отдельно, существующие
  не рефакторил.
- **`DaysOfStockCalculator::$lastSkipped`** — изменяемое состояние калькулятора (сбрасывается в начале `calculate()`);
  для одного прогона достаточно.
- **Объём таблицы:** `days_since_last_sale` пишется для всех товаров с остатком каждый месяц (Large ≈ 5 000 строк/мес.),
  `days_of_stock` — для пар с продажами (до 25 000/мес.).
- **Turnover и остальные калькуляторы** по-прежнему считают «относительный остаток от 0» (отмечено в отчёте этапа 06).
- **История коммитов:** `c3c470f` и `b567da2` теперь существуют только в ветке `backup/stage6-contracts`.

## 8. Открытые вопросы

1. **День прихода (п. 4.3).** Оставить определение «остаток на начало дня > 0», или считать день «в наличии», если остаток
   на начало дня + приходы этого дня > 0? Второе убирает +14 % после провалов, но меняет формулу из задания.
2. **historyEnd источника.** Добавить в контракт (например, «до какой даты у источника есть данные») или продолжать
   ограничиваться границей диапазона?
3. **Дефолтный период `metrics:calculate`.** Привязать мок к «сегодня» нельзя (детерминированность); может, для команды
   сдвинуть период по умолчанию на конец истории мока?
4. **Неликвиды и начало диапазона.** Автоматически смотреть назад на `dead_stock_days` от начала диапазона (лишний вызов
   адаптера), или оставить «доступная история = диапазон»?
5. **Пары без спроса.** Нужно ли писать для пар с остатком, но без продаж, отдельный признак (сейчас снэпшота нет,
   только счётчик в логе)?
