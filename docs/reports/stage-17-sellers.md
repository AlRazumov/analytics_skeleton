# Этап 17: продавцы (Seller) на моке — отчёт

## 1. Разведка: что было в репозитории

Задание писалось под другую структуру; фактическая:

- Сделка — не Eloquent-модель, а readonly-DTO `Core/Domain/Deal` (id, productId,
  amount, date, meta). Пайплайн НЕ пишет сделки в БД: калькуляторы читают
  `fetchDeals()` из адаптера в памяти и пишут только `metrics_snapshots`.
  `staging_deals`/`StagingDeal` существуют, но пайплайном не используются.
- Связь с товаром — по внешнему строковому id (`product_external_id`), без FK.
  Так же сделан продавец.
- Сущности «филиал» нет; ближайший аналог — склад (`staging_warehouses`,
  id вида `wh-N`).
- `DataSourceAdapter::capabilities()` возвращает `list<AdapterCapability>`, а не
  массив с ключами.
- Отдельного job-агрегатора нет: `MetricsCalculationService` (вызывается
  `metrics:calculate`) → `MetricsSnapshotWriter`. Топ товаров — не хранимый rank, а
  запрос `MetricsComparisonRepository::top()` (ORDER BY value LIMIT в БД), поэтому rank
  для продавцов тоже не хранится.
- Виджет топа товаров — `ProductTablesProvider::topProducts` + `top-products-table`
  (выручка с дельтами к базе), отдельная страница; общего «топ-N по entity_type» не было.
- Экспорта отчётов нет.
- Период в снэпшотах — `month:YYYY-MM`; записи по месяцам.
- Тесты: Pest, Feature/Unit; фейковые адаптеры в `tests/Pest.php`.

## 2. Нумерация

Этап 07 в roadmap уже занят (метрики остатков), 15/16 зарезервированы под реальные
адаптеры, поэтому работа оформлена как **этап 17** (строка в roadmap,
`docs/stages/stage-17-sellers.md`, этот отчёт в `docs/reports/`), а не
`docs/stage-7-report.md`. Существующие файлы этапов не менялись.

## 3. Созданные и изменённые файлы

Новые: `Core/Domain/Seller.php`, `Core/Domain/Enums/SellerCoverage.php`,
`Core/Staging/StagingSeller.php`, `Core/Analytics/Sellers/*` (интерфейс `SellerMetric`,
`SellerSalesData`, `SellerMetricsCalculator`, 6 метрик), `Core/Widgets/TopNProvider.php`,
`Core/Widgets/Contracts/EntityNameResolver.php`, `Core/Widgets/DTO/TopNData|TopNRow.php`,
`Repositories/DbEntityNameResolver.php`, `View/Components/Widgets/TopN.php`,
`components/widgets/top-n.blade.php`, миграция `2026_09_21_100000_create_staging_sellers_table`,
`tests/Feature/Sellers/*`, `docs/stages/stage-17-sellers.md`.

Изменённые: `Deal` (+`sellerId`), `DataSourceAdapter` (+`fetchSellers`, `sellerCoverage`),
`MockAdapter`, `MockDataProfile` (+`sellerCount`), `DataSourceAdapterFactory`,
`MetricsCalculationService`, `ReferenceSyncService` (+sellers), `CalculateMetrics`
(+удаление seller-метрик перед записью), `StagingDeal`, `AppServiceProvider`,
`OverviewDashboardController`/`overview.blade.php`, `MetricLabels`, `config/analytics.php`,
`docs/features.md`, `docs/roadmap.md`, тестовые фейки адаптеров (`tests/Pest.php`,
`ReferenceSyncTest`) и три теста с константами (набор metric_key, число canvas на обзоре,
набор флагов).

## 4. Решения и отклонения от задания

| Задание | Сделано | Причина |
|---|---|---|
| таблица `sellers` с FK на филиал, `deals.seller_id` FK | `staging_sellers` (external_id unique, name, `branch_external_id`, is_active, meta), `staging_deals.seller_external_id` nullable + индекс, без FK | таблицы сделок с FK нет; связь по внешнему id — как у товаров. Колонки `source` нет: staging-таблицы её не имеют. Филиал = склад мока |
| Импорт «продавцы раньше сделок» | `ReferenceSyncService` синхронизирует продавцов; сделки в БД не импортируются | пайплайн считает из памяти |
| DTO `SellerData` | `Seller` | конвенция имён (`Deal`, `Warehouse`, `Product`) |
| `capabilities()` с ключами `sellers`/`seller_on_deal` | отдельный `sellerCoverage(): SellerCoverage` (full/partial/none) | `capabilities()` — список enum'ов, менять его форму значит ломать контракт |
| `compute(Period)` | `compute(SellerSalesData, Period)` | сделки не в БД, нужны свёрнутые данные; классы метрик чистые, без Laravel |
| `MetricDefinition` | `SellerMetric` | имя привязано к типу сущности |
| оконные функции Postgres для trend | PHP по свёрнутым данным | сделки в памяти, БД не участвует |
| sales_amount нетто | сумма `Deal::amount` как есть; возвраты учитываются, если источник отдаёт их отрицательными сделками | в `Deal` нет признака возврата/типа документа; закомментировано в `SellerSalesData::fromDeals` |
| без продавца — `entity_id = null` или ключ | ключ `__none__` (`SellerSalesData::NO_SELLER`) | `entity_id` в `metrics_snapshots` не nullable; строка несёт `value_meta.seller_on_deal` |
| share_of_total | доля в **количестве** продаж, % | опора на формулировку заказчика («количество и топ-3») |
| sales_per_active_day | продаж в месяце / дней от первой до последней продажи продавца (усечено месяцем) | нормирует новичка и уволенного; «без продавца» пропускается |
| обобщить виджет топ товаров | новый общий `TopNProvider` + `<x-widgets.top-n>`; страница и виджет топ товаров **не менялись** | у товаров сравнение с базой и дельты, у продавцов — другие колонки; гарантия «не изменилось» — не трогать. Регрессионный тест сверяет порядок с `topProducts`. `TopNProvider` умеет и `product` |
| продавец в момент генерации сделки | в цикле сделки, но случайные числа — из отдельного потока (`deal-sellers:YYYY-MM`) | основной поток (товар, сумма, дата) не сдвигается; тест это проверяет |
| профили меняют число продавцов | Small 12, Medium 24, Large 48; кривая весов интерполируется | топ-3 ≈ 52% только на 12; на 24/48 доля топ-3 ниже (форма та же) |
| 5–10% без продавца | 7% (full), 35% (partial) | |
| уволенный / новичок | seller-5: до середины окна, `is_active=false`; seller-8: последние 28 дней | по одному на каждую «двенадцатку» |
| флаг | `features.sellers` (`ANALYTICS_FEATURE_SELLERS`) | блок скрывается флагом, как остальные разделы |

Метрики, выключенные в `enabled_metrics.seller`, не считаются и не показываются (при
смене конфига нужен `metrics:calculate`, старые строки удаляются перед записью). Если
выключен `sales_count`, блок не рендерится (он ранжирующая метрика и источник режима покрытия).

Режим покрытия хранится в `value_meta.seller_on_deal` строки «без продавца», потому что
виджет не знает адаптера. При `none` строки продавцов не пишутся вовсе — блока нет.

## 5. Тесты

Полный прогон (`sail artisan test`, включая slow): **455 passed (95 435 assertions)**, 159 с.
Новые: `tests/Feature/Sellers/SellerMetricsTest` (инвариант суммы по продавцам + «без»
= итог для количества, суммы и долей; ключ `__none__`; sales_per_active_day новичка и
уволенного; trend; ростер мока, детерминизм, топ-3 в 45–60%, «без продавца» 5–10%; три
режима; неизменность потока сделок) и `SellerWidgetTest` (топ-3 на сидированных данных
без «Без продавца»; full/partial с процентом/none; флаг; отключённая метрика; регрессия
топа товаров). Пришлось обновить 3 теста с константами (набор metric_key, canvas на
обзоре 2→3, набор флагов) и фейковые адаптеры. Pint прогнан; статанализатор в проекте не настроен.

## 6. Нерешённое

- Экспорта отчётов в проекте нет — пункт не делался.
- Продавцы показываются только на обзоре; отдельной страницы и выбора метрики через
  query (`?metric=`) нет — метрика задаётся атрибутом компонента.
- Признак возврата в `Deal` отсутствует: нужен, когда появится реальный источник.
- `staging_deals.seller_external_id` заведён, но сделки в БД никто не пишет.
- Реальные адаптеры и SellerResolver для 1С — после ответа 1С-стороны.

## 7. Что переслать 1С-подрядчику

> Для разбивки продаж по продавцам нам нужно от сервиса 1С:
> 1. **Список продавцов**: стабильный идентификатор (не меняется при переименовании и
>    пересоздании карточки; строка до 64 символов), имя, признак «работает / уволен»,
>    по возможности — идентификатор подразделения/склада, к которому привязан.
> 2. **В каждой продаже (реализации) — идентификатор продавца** (тот же, что в списке).
>    Если у продажи продавца нет — пустое значение, а не «0» или «неизвестен».
> 3. **Возвраты**: тип документа или признак «возврат», чтобы считать продажи нетто.
> 4. Идентификатор продажи, дата и сумма — как в текущей выгрузке.
> Без пунктов 1 и 2 разбивка по продавцам работать не будет; если продавец указан не во
> всех продажах, мы покажем данные с пометкой о доле покрытия.
