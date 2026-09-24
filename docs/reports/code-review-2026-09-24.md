# Отчёт: обзор кода на логику, ошибки и недоделки (code review, 2026-09-24)

Анализ по HEAD `a97c219` (merge `docs-refresh`), ветка `master`. Ничего не
правилось — только фиксация наблюдений для последующей перепроверки.

## Итог перепроверки (2026-09-24)

Пункты сверены с кодом. Статусы:

| Пункт | Вердикт | Что сделано |
|---|---|---|
| 1.1 | подтверждён, баг | исправлено: delete+write в `DB::transaction`, тест на откат |
| 1.2 | подтверждён, серьёзность завышена | не правится (см. примечание в п. 1.2) |
| 1.3 | **неверен** | не баг (см. примечание в п. 1.3) |
| 2.1 | подтверждён, баг | исправлено: `stock_no_demand` и для пар, открывших окно с нуля; тесты |
| 2.2, 2.3 | подтверждены, латентно | open-пункт в Known issues `docs/roadmap.md` |
| 3.3 | подтверждён частично | только без `?period` и только если у одной из метрик нет строк за последний месяц |
| §6 `CreateUser` | **неверен** | email приводится к lowercase до валидации (`CreateUser.php:29`) |
| §8 | в основном **неверен** | исторические записи не ошибки; исправлены только докблок `MockScenarioConfig` и `docs/demo.md` |

Остальное (§3.1–3.2, 3.4, §4–§7) выборочно подтверждено, латентные мелочи —
не правились.

## Что прогнано

| Проверка | Команда | Результат |
|---|---|---|
| Статический анализ | `sail php vendor/bin/phpstan analyse --no-progress` (level 8, app/) | passed, 0 errors |
| Стиль | `sail php vendor/bin/pint --test` | passed |
| Все тесты | `sail artisan test` | **520 passed / 96 577 assertions**, 181.6 c |

---

## 1. Реальные ошибки (целостность данных)

### 1.1 `metrics:calculate` — delete + insert не транзакционны (bug)
`app/Console/Commands/CalculateMetrics.php:83-84` — сначала
`deleteExistingSnapshots()`, затем `write()` пачками по 500
(`app/Repositories/EloquentMetricsSnapshotWriter.php:39-41`). Если `write()`
бросит исключение или процесс прервётся — месяц уже очищен, новые строки
лишь частичны; дашборды получают пустую/обрезанную картину без пути
восстановления. Нужна `DB::transaction` вокруг delete+write.

> **Перепроверка:** подтверждено, исправлено.

### 1.2 ABC/XYZ — delete-диапазон шире write-диапазона → теряется история (bug)
`CalculateMetrics.php:109-128` удаляет снэпшоты **за все месяцы** диапазона,
а `AbcClassifier`/`XyzClassifier` пишут снэпшот **только за последний месяц**
(`AbcClassifier.php:55,74`, `XyzClassifier.php:68,102`).
Сценарий потери: прогон `2024-01..2025-12` → записан `abc_xyz` за 2025-12;
затем прогон `2024-01..2026-08` → строки за 2025-12 удалены и не переписаны.
Матрица продолжает показывать последний период, но историческая
классификация молча уничтожена.

> **Перепроверка:** расхождение delete/write есть, но «потеря истории» —
> преувеличение. ABC/XYZ по замыслу — один актуальный срез
> (`MetricsSnapshotRepository::latestPeriodFor()`, докблок), историю
> классификации никто не читает; матрица берёт только последний период.
> Практических потерь нет, не правится.

### 1.3 Безусловный delete stock-метрик (латентный bug)
`CalculateMetrics.php:36-50,120-128` удаляет `turnover`,
`days_since_last_sale`, `days_of_stock`, `stock_no_demand` всегда, но
`MetricsCalculationService::calculate()` считает их только при capabilities
`StockMovements + StockSnapshots` (`MetricsCalculationService.php:118-130`).
Реальный адаптер без stock-возможностей после повторного прогона застревает
метриками. На моке (capabilities есть всегда) не проявляется.

> **Перепроверка: пункт неверен.** Эффект обратный: старые stock-метрики
> удаляются, новые не пишутся — «застревания» нет. И это правильное
> поведение: без stock-возможностей уцелевшие строки были бы устаревшими
> данными прежнего источника. Не баг.

---

## 2. Логические нестыковки калькуляторов

### 2.1 `stock_no_demand` пропускает пары, открывшие окно с нуля (inconsistency)
`app/Core/Analytics/DaysOfStockCalculator.php:112-133` — итерация только по
`$opening` (остаток на `windowStart − 1 день`). Пара с нулевым остатком на
начало окна, получившая товар внутри окна (Receipt/TransferIn) и без продаж,
не получает ни `days_of_stock`, ни `stock_no_demand`, не увеличивает
`lastSkipped['no_demand']`. Противоречит докблоку «пишется, только когда
итоговый остаток на конец месяца положителен» (строки 56-64). Для
`TransferRecommendationService` (читает только эту метрику,
`TransferRecommendationService.php:77-86`) такой склад невидим.

> **Перепроверка:** подтверждено, исправлено (обход объединения ключей
> остатка на начало окна и движений в окне; тесты в
> `DaysOfStockCalculatorTest`).

### 2.2 `LostSalesCalculator` проверяет «нет сделок» по net > 0 (inconsistency)
`app/Core/Analytics/LostSalesCalculator.php:63,71` — условие
`($byMonth[$currentKey] ?? 0.0) > 0.0`; докблок обещает «в M нет ни одной
сделки». Месяц, где продажи аннулируются возвратами (net = 0), посчитается
«потерянным». На моке возвратов нет (`SellerSalesData.php:36-38`
трактует отрицательные суммы как возвраты) — латентно до реальных адаптеров.

> **Перепроверка:** подтверждено. Не гипотетика: `docs/adapter-contract.md`
> явно допускает отрицательные `deals.amount` (возврат). Вместе с 2.3 —
> open-пункт в Known issues roadmap.

### 2.3 `AbcClassifier` ломается на отрицательных сделках (inconsistency)
`AbcClassifier.php:61` — `cumulativeShare = $cumulative / $grandTotal`; при
возвратах доля превышает 1 (A=100, B=-30 → топ-товар даёт 1.43 → класс C),
при `grandTotal <= 0` все товары принудительно становятся C. Латентно на
моке (отрицательных сделок нет).

### 2.4 `RevenueByPeriodCalculator` — мёртвый параметр `$period` (minor)
`RevenueByPeriodCalculator.php:23` — позиция не используется; записи
порождаются только месяцами с фактическими сделками, нулевой месяц
диапазона не даёт строки (виджеты это гасят `?? 0.0`).

---

## 3. Презентация / виджеты

### 3.1 Обзор: YoY-серия рисует плоский ноль без пояснения (inconsistency)
`app/Core/Widgets/WidgetDataProvider.php:55-69` + `overview.blade.php:13`.
Если история не покрывает прошлый год (профиль small = 12 мес., голый
`metrics:calculate` = 12 мес.), серия «год назад» — сплошные нули.
Страница топ-товаров эту ситуацию закрывает (`yearAgoAvailable`,
`TopProductsDashboardController.php:36`), overview — нет.

### 3.2 Несогласованное форматирование чисел (minor)
- `resources/views/components/widgets/table.blade.php:19` и
  `kpi-card.blade.php:9,16` — сырые float / `number_format(2)` (`150.00000001`,
  `1,234,567.89`), остальной UI — `Format::num` (`1 234 567,89`).
- `app/Support/TopNCsv.php:19` — `share_of_total` и `trend` в CSV без «%» и с
  2 знаками (`22.34`), на странице — `22.3%`.

### 3.3 `StockDashboardController` — период двух виджетов резолвится раздельно (minor)
`app/Http/Controllers/Dashboards/StockDashboardController.php:28-36` —
`deadStock` и `stockoutRisk` независимо зовут `latestPeriod(...)`; при
частичном бэкфилле (`--period`) виджеты покажут разные месяцы.

> **Перепроверка:** частично. Период общий, если задан `?period`; без него
> расходится, только если у одной из метрик нет строк за последний месяц
> (оба калькулятора пишут помесячно). Мелочь.

### 3.4 Латентные мелочи виджетов (minor)
- `WidgetDataProvider.php:209` — `precedingPeriodKeys()` хардкодит `'month'`
  для любой гранулярности, кроме `day` (week/quarter/year сломаны).
- `TopNProvider.php:49,102-118` — «Показано N из M» / режим покрытия
  считаются по топ-1000 до среза; при каталоге > 1000 числа занижаются, при
  выпадении `__none__` из среза режим покрытия молча исчезает.
- `top-n.blade.php` — пустой набор строк рисует пустой canvas без сообщения.
- `WidgetDataProvider.php:85` — `table()` кладёт в `TableData` сырые float;
  `:181` — выравнивание подписей по позиции, ломается при разной длине ключей.
- `OverviewDashboardController.php:26` — `latestPeriod('revenue', ...)` без
  `entity_type`; безопасно, пока revenue только у `product`.

---

## 4. Репозитории / портируемость

### 4.1 PostgreSQL-only код при sqlite-дефолте `.env.example` (inconsistency)
- `app/Repositories/EloquentMetricsComparisonRepository.php:36,73,126,141,145` —
  `COLLATE "C"`, `split_part`, `json_build_array`, `COUNT(*) FILTER`.
- `2026_09_19_100000_split_period_in_metrics_snapshots_table.php:27-30,34,80-84` —
  regex `~`, `to_date`, `to_char`, `INTERVAL`.
- `.env.example:23` — `DB_CONNECTION=sqlite`. Новый клон по `.env.example` без
  README падает на SQLite. Признано в `docs/reports/stage-12-showcase.md:173`.

### 4.2 `split_period` миграция без дедуп-пасса (minor)
`...split_period...php:52-56` — unique-индекс создаётся без проверки дублей;
старая БД с накопленными дублями упадёт уже после удаления `period`.

### 4.3 `latestPeriod` в comparison-репозитории без `entity_type` (minor)
`EloquentMetricsComparisonRepository.php:93-103` — `where('metric_key', ...)`,
без фильтра по сущности. Сейчас безопасно (revenue пишется только для
`product`).

---

## 5. Staging / миграции / будущие адаптеры

- **`external_id` не unique** у `staging_deals`/`staging_stock_movements`
  (`2026_09_08_184933...php:13`, `2026_09_08_184934...php:13`) — только индекс;
  у products/warehouses/sellers — unique. Будущий `upsert`-синк не
  дедуплицируется.
- **`staging_deals`/`staging_stock_movements` ничем не заполняются** — во
  всём `app/` нет писателей (модели + миграции есть).
- **`staging_stock_movements.to_warehouse_external_id` ничем не пишется** —
  в `StockMovement` нет доменного `toWarehouseId` (трансфер = пара
  `transfer_out`/`transfer_in` + `meta.transfer_id`, `StockMovement.php:16-19`).
  Доки этапов 01/02 («добавлен toWarehouseId») устарели.
- **`staging_sellers.meta` всегда `null`** — `ReferenceSyncService.php:47`;
  колонка jsonb и каст есть (`2026_09_21_...php:17`, `StagingSeller.php:22`),
  а в DTO `Seller` поля `meta` нет.

---

## 6. Команды / безопасность

- `app/Console/Commands/ChangeUserPassword.php:49` — смена пароля не
  инвалидирует сессии и `remember_token`.
- ~~`app/Console/Commands/CreateUser.php:35` — `Rule::unique('users','email')`
  регистрозависим, Fortify же приводит email к lowercase; дубль в другом
  регистре проскочит, а исходный аккаунт станет «недостижим» при логине.~~
  **Перепроверка: неверно** — email приводится к нижнему регистру до
  валидации (`CreateUser.php:29`), дубль в другом регистре невозможен.
- `config/fortify.php:117-118` ссылается на лимитеры `two-factor`/`passkeys`,
  которые **не зарегистрированы** в `FortifyServiceProvider` (есть только
  `login`) — при включении 2FA/passkeys будет runtime-ошибка.
- `database/seeders/DatabaseSeeder.php` — пароль фабрики `'password'` (7 сим.)
  ниже политики `users:create` (>= 12).
- `resources/views/auth/login.blade.php:5` — POST на `route('login')` (GET-имя);
  работает только из-за совпадения URI `/login`, хрупкая связка
  (корректнее `route('login.store')`).
- `app/Console/Commands/InstallDemo.php:27` — `REQUIRED_TABLES` без
  `staging_sellers`; при отсутствии только этой таблицы — сырой PDO вместо
  аккуратного сообщения.
- `app/Console/Commands/SyncReferences.php:27` — вывод не упоминает продавцов,
  хотя `reference:sync` их синкает (`ReferenceSyncService.php:52`).

---

## 7. Адаптер-контракт (латентное)

- `app/Adapters/Contract/AdapterContractChecker.php` — пустой источник
  (нет ни товаров, ни сделок, ни остатков) проходит все правила вакуумно;
  команда `adapter:check` печатает «Нарушений нет» и выходит с 0
  (`CheckAdapter.php:54-57`).
- Dead-товары также становятся `stock_no_demand`-донорами
  `TransferDonorReason::StockSurplus`, а не только сценарий `no_sales_donor`
  (демо-эвристика не эксклюзивна, тестом не покрыто).
- `MockAdapter.php:977` — `crc32(seed.':context')` как сид `Mt19937`:
  теоретическая коллизия пар (seed, context) и отличие на 32-бит PHP.
- `MockAdapter.php:217,260` — при нестандартных (не с 1-го числа)
  `historyStart`/`historyEnd` сдвигается разбиение по месяцам: последний месяц
  окна подавляет все lost-sales-сделки, первый месяц «утяжеляется» наружу
  окна. Кода-пути с кастомными границами сейчас нет.
- Две независимые системы сезонности: у сделок — NewYearPattern (Nov 1.3 /
  Dec 1.8 / Jan 1.2, применяется ко всем товарам), у движений «seasonal» —
  косинус с пиком 1.8× в декабре (Jan ≈ 1.6-1.8×, June 0.2×). Параметры не
  связаны (косметика, задокументировано только в манифесте).

---

## 8. Доки vs код

| Файл | Расхождение |
|---|---|
| `docs/roadmap.md:93` | «50 tests / 5822 assertions»; по факту 520 / 96 577 — **не ошибка**: запись о состоянии на 2026-09-14 |
| `docs/reports/stage-11-source-reference-overview.md:81,101,110` | `/demo/widgets` (DemoWidgetsController) удалён на этапе 12 — **не ошибка**: отчёт этапа, задним числом не переписывается |
| `docs/reports/stage-01-report.md:104-113`, `stage-02-report.md:57-89` | упоминания `toWarehouseId` у `StockMovement` — свойства больше нет — **не ошибка**: отчёты этапов исторические |
| `docs/demo.md` | env `ANALYTICS_MOCK_SELLER_COVERAGE` (`config/analytics.php:21`) не документирован — **исправлено** |
| `app/Adapters/Mock/MockScenarioConfig.php:8-9` | докблок перечисляет сценарии без `lost_sales` и `no_sales_donor` — **исправлено** |
| `MockScenarioConfig.php:33` | ссылка на несуществующий `MockAdapter::isLostSalesProduct` — **исправлено** (`lostSalesGuaranteedDeals`) |
| `docs/reports/stage-13-mock-realism.md:57-62` | фингерпринты Small устарели: тесты теперь 2678 / 677501.92 (было 2660 / 673591.97) — помечено в тестах — **не ошибка**: отчёт этапа исторический |

**Подтверждено консистентным** (сверить не требуется): адаптер-контракт
реализован полностью (docs/adapter-contract.md ↔ AdapterContractChecker),
features.md ↔ config/analytics.php, security.md ↔ роуты, профили и сценарии
мока ↔ docs/demo.md, CLAUDE.md ↔ дерево каталогов, все команды из доков
существуют, `latestPeriodFor` = `ORDER BY period_start DESC, id DESC`
(соответствует закрытому пункту roadmap).

---

## 9. Резюме

Критических падений нет: PHPStan и Pint чисты, 520 тестов зелёные.
После перепроверки реальных багов два — п. 1.1 (транзакционность
`CalculateMetrics`) и п. 2.1 (`stock_no_demand`), оба исправлены; п. 1.2
малозначим, п. 1.3 — не баг (см. «Итог перепроверки» в начале).
Остальное — латентные нестыковки, проявляющиеся на реальных адаптерах
(возвраты, SQLite, staging-синк), и мелкие места в доках.