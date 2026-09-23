# Roadmap

Новые модули/источники данных добавляются новой строкой в этой таблице
и новым файлом `stages/stage-NN-*.md`. Существующие файлы этапов не
переписываются задним числом. Нумерация ведётся с запасом (00, 01...),
чтобы при необходимости можно было вставить промежуточный этап,
например `stage-02b-*.md`.

| # | Этап | Файл | Статус | Зависит от |
|---|------|------|--------|------------|
| 00 | Setup: структура репо, миграция metrics_snapshots, Pest | stages/stage-00-setup.md | done | — |
| 01 | Core-модели + контракт DataSourceAdapter | stages/stage-01-core-models.md | done (2026-09-09: постфактум-правка StockMovement — добавлено toWarehouseId для Transfer, см. отчёт) | 00 |
| 02 | MockAdapter | stages/stage-02-mock-adapter.md | done | 01 |
| 03 | Widgets (Chart.js) | stages/stage-03-widgets.md | done | 02 |
| 04 | Расчётный пайплайн (adapters → metrics_snapshots) | stages/stage-04-metrics-pipeline.md | done | 02, 03 |
| 05 | Presentation (StandaloneLayout) | stages/stage-05-presentation.md | done (IframeLayout вынесен на будущий этап — ждёт доступа к Б24-порталу) | 03 |
| 06 | Контракты движений/остатков, Period, metrics_snapshots, MockAdapter (часть 1) | stages/stage-06-contracts-and-mock.md | done (часть 1; см. reports/stage6-part1.md) | 01, 02, 04 |
| 07 | Метрики остатков: неликвиды (days_since_last_sale), дни до обнуления (days_of_stock) | stages/stage-07-stock-metrics.md | done | 06 |
| 08 | Правки метрик остатков, сравнение периодов, топ/анти-топ | stages/stage-08-period-comparison-top.md | done (см. reports/stage-08-period-comparison.md) | 06, 07 |
| 09 | Оборачиваемость: стартовый остаток; аутентификация standalone-части (Fortify) | stages/stage-09-turnover-and-auth.md | done (см. reports/stage-09-turnover-and-auth.md) | 06, 07, 08 |
| 10 | Виджеты новых метрик (неликвиды, риск дефицита, топ/анти-топ), флаги функциональности, гигиена аутентификации | stages/stage-10-widgets-flags-hygiene.md | done (см. reports/stage-10-widgets-flags-hygiene.md) | 07, 08, 09 |
| 11 | Выбор источника данных, справочники товаров и складов, исправление overview | stages/stage-11-source-reference-overview.md | done (см. reports/stage-11-source-reference-overview.md) | 09, 10 |
| 12 | Витрина на моке: оборачиваемость, графики новых метрик, YoY, демо-режим | stages/stage-12-showcase.md | done (см. reports/stage-12-showcase.md) | 09, 10, 11 |
| 13 | Реализм мока и гигиена витрины: детерминизм сделок, разброс возрастов неликвидов, сценарий дисбаланса складов, Chart.js локально, подписи overview | stages/stage-13-mock-realism.md | done (см. reports/stage-13-mock-realism.md) | 12 |
| 14 | Рекомендации перемещений между складами | stages/stage-14-transfer-recommendations.md | done (см. reports/stage-14-transfer-recommendations.md) | 13 |
| 15 | Bitrix24Adapter | — | not planned (отложен на неопределённый срок, ждёт клиента) | 06 |
| 16 | OneCAdapter | — | not planned (отложен на неопределённый срок; заказчик тянет время, может не состояться) | 06 |
| 17 | Продавцы (Seller): справочник, метрики по реестру, топ-N на обзоре; на моке, без реальных адаптеров | stages/stage-17-sellers.md | done (см. reports/stage-17-sellers.md) | 06, 13 |

## TODO before real adapters

Пункты, которые нужно пересмотреть перед стартом этапов 15/16 (реальные,
нестейтлес-адаптеры), а не при их планировании задним числом:

- ✅ done: пересмотреть `MetricsCalculationService` (тройной вызов
  `fetch*` за прогон) — см. `docs/reports/stage-04-report.md`, раздел
  «Наблюдение: контракт "один вызов fetch* за прогон" не соблюдается
  буквально». Решено отдельным follow-up коммитом после этапа 04:
  калькуляторы теперь принимают данные аргументом, `fetchDeals()`/
  `fetchStockMovements()` вызываются сервисом ровно по одному разу за
  `calculate()`.

- ✅ done: пустая ABC/XYZ-матрица при рассинхроне периодов (был открыт
  как "Known issues" после этапа 05). Причина: `period` у
  `AbcClassifier`/`XyzClassifier` — последний месяц ВСЕГО диапазона,
  переданного в `calculate()`, а не помесячная серия, но каждый
  consumer (`DemoWidgetsController`, `dashboards.abc-xyz`) сам
  придумывал, какой `Period` запрашивать — при рассинхроне с реальным
  периодом прогона `metrics:calculate` матрица молча оказывалась
  пустой. Исправлено bugfix-коммитом после этапа 05: в контракт
  `MetricsSnapshotRepository` добавлен `latestPeriodFor(entityType,
  metricKey): ?string` — единственный источник знания о том, как
  искать актуальный ABC/XYZ-снэпшот (последний по `period` DESC).
  `WidgetDataProvider::abcXyzMatrix()` больше не принимает `Period` —
  сам спрашивает `latestPeriodFor()` у репозитория. Оба consumer'а
  (`DemoWidgetsController`, `AbcXyzDashboardController`) переключены на
  вызов без периода. Расчёт ABC/XYZ в `MetricsCalculationService` и
  смысл `period` при записи не менялись (переход на помесячную запись
  — отдельное потенциальное решение, если появится реальный спрос).

- ✅ done: первый фикс `latestPeriodFor()` (пункт выше) оказался
  неполным — он путал "снэпшот с численно/лексикографически большим
  `period`" с "снэпшот, рассчитанный последним по факту". При бэкфилле
  старого периода (`metrics:calculate --period=...` за окно раньше уже
  посчитанного) `metrics:calculate` удаляет и перезаписывает снэпшоты
  только за запрошенный диапазон — более новый по значению `period`
  снэпшот от предыдущего прогона не трогается и по-прежнему
  "выигрывал" в `ORDER BY period DESC`, хотя реально только что был
  пересчитан другой период. Исправлено bugfix-коммитом: критерий
  выбора в `EloquentMetricsSnapshotRepository::latestPeriodFor()`
  сменён на `ORDER BY created_at DESC, id DESC` — `EloquentMetricsSnapshotWriter`
  всегда делает delete+insert новых строк (не upsert), поэтому
  `created_at` каждой строки достоверно отражает момент её записи;
  `id` — детерминированный tie-break внутри одного прогона (общий
  `created_at` на пачку). Контракт метода и сигнатура не менялись,
  ABC/XYZ-классификатор не трогался. Регрессионный тест —
  `tests/Feature/Repositories/EloquentMetricsSnapshotRepositoryTest.php`.

## Known issues

- ✅ closed (2026-09-14): Pint: 7 нарушений (`ordered_imports`,
  `single_line_empty_body`×3, `new_with_parentheses`×3) в файлах этапа
  01 (`DataSourceAdapter`, `DateRange`, `Deal`, `Product` + их тесты) —
  см. `docs/reports/stage-02-report.md`. Ранее осознанно не
  исправлялись по правилу CLAUDE.md «файлы этапов не переписываются
  задним числом» — но это правило про содержательные изменения логики,
  а не про форматирование. При финальной уборке перед паузой проекта
  прогнан `vendor/bin/pint` по всему проекту (реальное исправление, не
  `--test`): исправлены ровно эти 7 файлов, логика не менялась.
  `vendor/bin/pint --test` проходит чисто по всему проекту, полный
  набор тестов (`sail artisan test`, 50 tests / 5822 assertions)
  остаётся зелёным.

- ✅ closed (2026-09-14): пустые `app/Widgets/` и `app/Presentation/`
  (только `.gitkeep` со stage-00) — расхождение с деревом каталогов в
  CLAUDE.md. Реальный код виджетов лежит в `app/Core/Widgets/`, а
  presentation — в `resources/views/components/layouts/` и
  `app/Http/Controllers/Dashboards/*`; причины задокументированы в
  `docs/reports/stage-03-report.md`. Пустые директории удалены, дерево
  каталогов в CLAUDE.md приведено в соответствие фактической
  структуре. Выделение отдельных namespace'ов под widgets/presentation
  не создавалось — это осознанно отложено до появления реальных
  адаптеров (`Bitrix24Adapter`/`OneCAdapter`), когда будет видно, нужна
  ли такая изоляция.

- ⚠️ open (2026-09-19, найдено при анализе HEAD `3ddd752`): раздел про
  bugfix `latestPeriodFor()` выше в этом файле устарел и противоречит коду.
  Там сказано «критерий сменён на `ORDER BY created_at DESC, id DESC`», но
  на этапе 11 (коммит `86a8da6`) семантика сознательно возвращена на
  `ORDER BY period_start DESC, id DESC`
  (`app/Repositories/EloquentMetricsSnapshotRepository.php`): ABC/XYZ-матрица
  должна показывать период последнего прогона расчёта, а не «снэпшот,
  записанный последним»; регрессия бэкфилла закрыта тестом
  `OverviewPageTest`. Актуальная семантика описана в контракте
  `app/Core/Widgets/Contracts/MetricsSnapshotRepository.php`. Пункт 57–73
  ретроспективно не переписывается (правило CLAUDE.md); актуальным
  признаётся поведение этапа 11.

- ✅ closed (2026-09-20, «Хвосты этапа 11», п. 1): расхождение выше снято
  документально. С этапа 11 критерий выбора периода в
  `latestPeriodFor()` — `ORDER BY period_start DESC, id DESC`;
  актуальным считается поведение этапа 11. Старый текст про
  `created_at DESC` не переписывался (правило CLAUDE.md), код не менялся.

- ✅ closed (2026-09-20, «Хвосты этапа 11», п. 2; было open с 2026-09-19):
  `MockAdapter::fetchDeals()` не ограничивает выдачу окном истории, в
  отличие от `fetchStock()` и `fetchStockMovements()` (те клампают к
  `historyStart()`…`historyEnd()`). Проверено прогоном: для
  `DateRange(2026-11-01..2026-12-31)` при `historyEnd = 2026-08-31`
  `fetchDeals()` отдаёт ~620 сделок, а `fetchStockMovements()` — 0.
  Докблок класса («вне окна истории данных нет») для сделок неверен.
  Следствие: `metrics:calculate --period` за пределы истории даст
  выручку/ABC-XYZ без остатков и оборачиваемости. Дефолтное
  12-месячное окно не затронуто. Решение — ограничить `fetchDeals()`
  историей либо задокументировать расхождение как контракт мока.

- ✅ closed (2026-09-20, «Хвосты этапа 11», п. 3; калькулятор не менялся, нули не пишутся — исправлена подпись; было open с 2026-09-19): «анти-топ» на
  странице «Топ товаров» строится по товарам, у которых в месяце есть
  снэпшот выручки (ASC по value), — товары без единой продажи за месяц
  снэпшота не имеют и в анти-топ не попадают. Это «худшие из
  продающихся», а не худшие вообще; на моке (продажи у всех товаров)
  расхождения с ожиданием не видно, на реальных данных — будет.

- ⚠️ open (2026-09-20, «Хвосты этапа 11», п. 4): товары, у которых продажи
  упали до нуля (потерянные продажи), не видны нигде: `compare()`/`top()`
  строятся по сущностям текущего периода. Решать, когда клиент скажет,
  нужна ли такая выборка.

- ⚠️ open (2026-09-20, этап 14): склад с остатком, но без продаж, не считается
  донором рекомендаций перемещений — для такой пары нет строки
  `days_of_stock` (метрика не пишется без спроса). Дефицит на одном складе
  и «мёртвый» остаток на другом дадут «дефицит без донора». Поведение
  зафиксировано тестом `TransferRecommendationServiceTest`.

- ✅ closed (2026-09-23): слой приложения — команда `metrics:calculate`
  (`resolvePeriod`) — зависел от конкретного адаптера через
  `instanceof MockAdapter` и `historyEnd()`; `historyEnd()` есть только у
  `MockAdapter`. Ядро (`core`) было чистым: связь была на уровне слоя
  приложения, не core. Исправлено: добавлен опциональный интерфейс
  `App\Core\Contracts\ProvidesHistoryBounds` (метод `historyEnd():
  DateTimeImmutable`), `MockAdapter` его реализует; `resolvePeriod` проверяет
  `instanceof ProvidesHistoryBounds` вместо `instanceof MockAdapter`.
  Контракт `DataSourceAdapter` не расширялся — реальный источник конец
  истории сообщить не обязан (решение этапа 07). Регрессионный тест на
  случай адаптера без этого интерфейса (будущие
  Bitrix24Adapter/OneCAdapter) — см. `docs/reports/resolve-period-history-bounds.md`.

## Хвосты этапа 11 (без номера этапа)

Не новый этап: нумерация и статусы этапов 12/13 не меняются. Отчёт —
`reports/stage-11-followups.md`.

1. ✅ done: расхождение docs и кода про `latestPeriodFor()` (только docs).
2. ✅ done: `MockAdapter::fetchDeals()` ограничен окном истории (основной вариант; выдача внутри окна не изменилась).
3. ✅ done: анти-топ — заголовок «Наименьшая выручка среди проданных за месяц» и пояснение про «Неликвиды» (при включённом флаге dead_stock).
4. ✅ done: запись в Known issues про потерянные продажи (docs).

## Статус проекта (обновлено 2026-09-19)

Этапы 00–05 полностью завершены и приняты; этап 06 (контракты и
MockAdapter, часть 1) выполнен 2026-09-19; этап 07 (метрики остатков) — тоже; этап 08 (правки метрик остатков, сравнение периодов, топ/анти-топ) — тоже; этап 09 (оборачиваемость со стартовым остатком, аутентификация standalone) — тоже; этап 10 (виджеты неликвидов/риска дефицита/топа, флаги, гигиена аутентификации) — тоже; этап 11 (выбор источника, справочники товаров и складов, overview) — тоже. Этап 12 (витрина на моке: оборачиваемость, графики, YoY, демо-режим) — выполнен 2026-09-20. Этап 13 (реализм мока, гигиена витрины) — выполнен 2026-09-20; этап 14 (рекомендации перемещений) — выполнен 2026-09-20. Этапы 15
(`Bitrix24Adapter`) и 16 (`OneCAdapter`) — not planned, ждут появления
реального клиента. Прежние Known issues (см. выше) закрыты; по итогам
анализа HEAD `3ddd752` три пункта закрыты в «Хвостах этапа 11»
(2026-09-20); открыт один — потерянные продажи (Known issues).
Проект поставлен на паузу до появления реального
клиента/доступа к Bitrix24/1С; следующий реальный шаг после этапов 13–14 — начать этап 15
или 16, когда появится клиент.
