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
| 18 | Страница продавцов (выбор метрики `?metric=`) и CSV-выгрузка | stages/stage-18-sellers-page.md | done (см. reports/stage-18-sellers-page.md) | 17 |
| 19 | Проверка контракта DataSourceAdapter: AdapterContractChecker, команда `adapter:check` | stages/stage-19-adapter-contract.md | done (см. reports/stage-19-adapter-contract.md) | 06, 17 |

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

- ✅ closed (2026-09-20, см. следующий пункт; было open с 2026-09-19,
  найдено при анализе HEAD `3ddd752`): раздел про
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

- ✅ closed (2026-09-23, реализовано как дефолтная эвристика для демо,
  финальное решение — при реальном клиенте; было open с 2026-09-20,
  «Хвосты этапа 11», п. 4): товары, у которых продажи упали до нуля
  (потерянные продажи), не видны нигде: `compare()`/`top()` строятся по
  сущностям текущего периода. Добавлена метрика `lost_sales`
  (entity_type='product') — `App\Core\Analytics\LostSalesCalculator`:
  товар, у которого в текущем месяце сделок нет, а в одном из
  `analytics.lost_sales.horizon_months` (конфиг, не хардкод) предыдущих
  месяцев были, — value — выручка ближайшего такого месяца.
  ОГРАНИЧЕНИЕ явно задокументировано в докблоке класса: горизонт ищется
  только внутри запрошенного диапазона, у начала диапазона видимость
  сужена. Сценарий в MockAdapter (`lost_sales`, отдельная от seasonal
  категория товаров, только на уровне сделок — не на уровне остатков) и
  тесты — см. `docs/reports/lost-sales-and-stock-surplus-donor.md`.

- ✅ closed (2026-09-23, реализовано как дефолтная эвристика для демо,
  финальное решение — при реальном клиенте; было open с 2026-09-20,
  этап 14): склад с остатком, но без продаж, не считался донором
  рекомендаций перемещений — для такой пары нет строки `days_of_stock`
  (метрика не пишется без спроса). Добавлено: `DaysOfStockCalculator`
  дополнительно пишет `stock_no_demand` (entity_type='product_warehouse')
  для таких пар с положительным остатком; `TransferRecommendationService`
  для товаров с дефицитной парой читает эту метрику и, если остаток выше
  `analytics.transfers.stock_surplus_min_stock` (конфиг), включает склад
  донором «по остатку» — `TransferRecommendation::$reason` =
  `TransferDonorReason::StockSurplus` (обычный оборотный донор —
  `::Turnover`), явно отличимо от обычного донора. `TransferPlanner::plan()`
  принимает такие доноры вторым (необязательным) аргументом — старое
  поведение (без stock_no_demand данных) не изменилось, что подтверждено
  существующим тестом. Сценарий в MockAdapter (`no_sales_donor`) и тесты —
  см. `docs/reports/lost-sales-and-stock-surplus-donor.md`.

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

- ✅ closed (2026-09-24): CI на GitHub (`.github/workflows/tests.yml`, достался
  от заготовки Laravel) падал на каждом push: матрица PHP 8.3/8.4/8.5 при
  `composer.lock` под Symfony 8.1 (нужен PHP ≥ 8.4.1) — на 8.3 не ставились
  зависимости, `fail-fast` отменял остальные; тесты шли бы на SQLite, а
  проект рассчитан на Postgres. Исправлено: одна конфигурация как в Sail
  (PHP 8.5, service Postgres 18, БД `testing`), шаг `pint --test`, полный
  прогон с группой slow; `composer.json` — `php: ^8.4` (честное требование
  lock-файла, пакеты не менялись). Остальные workflow заготовки Laravel
  (`issues`, `pull-requests`, `update-changelog`, `dependabot-auto-merge`)
  к проекту не относятся — удалить на усмотрение владельца. Удалены
  2026-09-24; `.github/dependabot.yml` (еженедельные обновления
  GitHub Actions) оставлен.

- ✅ closed (2026-09-24): статического анализа не было. Подключён Larastan
  (`phpstan.neon`, уровень 8, `app/`), шаг в CI; без baseline и
  `@phpstan-ignore` — все 63 замечания исправлены в коде. По существу:
  `TopNCsv` не проверял результат `fopen`; `Period` молча получал `null` на
  невалидной дате вместо ошибки; `AppServiceProvider` не проверял, что классы
  из `analytics.metrics.seller` реализуют `SellerMetric`; `demo:install`
  полагался на `assert` вместо явной проверки на `MockAdapter`;
  `bucketCounts` собирал SQL из нелитеральных строк (теперь — только
  литералы и биндинги, один запрос, `json_build_array`). Остальное —
  уточнение типов (`array<string, mixed>` для meta, list/array-key и т.п.).
  В `AdapterContractChecker` выдача адаптера явно типизирована как
  недоверенная (`mixed`), чтобы проверки типов не выглядели «всегда true».

- ✅ closed (2026-09-24, по `reports/code-review-2026-09-24.md`, п. 1.1):
  `metrics:calculate` удалял снэпшоты диапазона и писал новые вне
  транзакции — сбой записи оставлял месяцы пустыми или обрезанными.
  Удаление и запись обёрнуты в `DB::transaction`; тест на откат — в
  `tests/Feature/StockMetricsCommandTest.php`.

- ✅ closed (2026-09-24, по `reports/code-review-2026-09-24.md`, п. 2.1):
  `DaysOfStockCalculator` писал `stock_no_demand` только для пар с
  остатком на начало окна — склад, получивший товар внутри окна с нуля и
  без продаж, не становился донором «по остатку» в
  `TransferRecommendationService`. Теперь учитываются и такие пары.

- open (2026-09-24, по `reports/code-review-2026-09-24.md`, пп. 2.2–2.3):
  возвраты. Контракт адаптера допускает отрицательные `deals.amount`
  (`docs/adapter-contract.md`), но часть калькуляторов их не учитывает:
  `LostSalesCalculator` считает месяц без сделок по нетто ≤ 0 (месяц, где
  продажи погашены возвратами, станет «потерянным»); в `AbcClassifier`
  при отрицательных суммах накопленная доля превышает 1, а при нетто ≤ 0
  все товары становятся C. На моке не проявляется (отрицательных сделок
  нет). Решать вместе с первым реальным адаптером, когда будет понятно,
  как источник отдаёт возвраты.

- ✅ closed (2026-09-24, по `reports/code-review-2026-09-24.md`, §6):
  `users:password` не завершал открытые сессии — на standalone-группу
  маршрутов добавлен `auth.session`, команда сбрасывает `remember_token`;
  `DatabaseSeeder` больше не создаёт пользователя с фабричным паролем;
  форма входа постит на `login.store`; из `config/fortify.php` убраны
  лимитеры незарегистрированных 2FA/passkeys; `demo:install` проверяет
  `staging_sellers`, `reference:sync` выводит число продавцов.

## Хвосты этапа 11 (без номера этапа)

Не новый этап: нумерация и статусы этапов 12/13 не меняются. Отчёт —
`reports/stage-11-followups.md`.

1. ✅ done: расхождение docs и кода про `latestPeriodFor()` (только docs).
2. ✅ done: `MockAdapter::fetchDeals()` ограничен окном истории (основной вариант; выдача внутри окна не изменилась).
3. ✅ done: анти-топ — заголовок «Наименьшая выручка среди проданных за месяц» и пояснение про «Неликвиды» (при включённом флаге dead_stock).
4. ✅ done: запись в Known issues про потерянные продажи (docs).

## Статус проекта (обновлено 2026-09-24)

Выполнены этапы 00–14, 17, 18 и 19; все Known issues закрыты (потерянные продажи
и донор без продаж — демо-эвристиками, финальное решение — при реальном
клиенте). Этапы 15 (`Bitrix24Adapter`) и 16 (`OneCAdapter`) — not planned,
ждут появления реального клиента.

Что ещё можно делать без клиента (необязательно):

- инструкция по развёртыванию без Sail (nginx/php-fpm, cron для
  `metrics:calculate`, пользователи);
- открытые вопросы отчёта этапа 14 (`reports/stage-14-transfer-recommendations.md`,
  §10, п. 1–3) — продуктовые решения, не баги;
- экспорт остальных страниц (сейчас CSV только у продавцов, этап 18);
- проверка страниц на Large-профиле мока.

Что ждёт клиента: реальные адаптеры и IframeLayout, `SellerResolver` для 1С
(запрос подрядчику — `reports/stage-17-sellers.md`, §7), признак возврата в
`Deal`, запись сделок в `staging_deals`, пороги демо-эвристик на реальных
данных, решение о выделении namespace'ов widgets/presentation. Первый шаг
с реальным источником — `adapter:check` (правила — `docs/adapter-contract.md`).

## Git/GitHub (обновлено 2026-09-24)

Репозиторий — GitHub `AlRazumov/analytics_skeleton`, основная ветка
`master`. Работа ведётся в ветках от `master`, вливается через PR после
зелёного CI (`.github/workflows/tests.yml`: PHP 8.5, Postgres 18, Pint,
PHPStan, полный прогон тестов с группой slow). После слияния ветка удаляется
на GitHub и локально.

Что влито 2026-09-24: этапы 07–14 и 17 (PR `stage-14-review-fixes`,
fast-forward), этап 18 (fast-forward), починка CI (PR #3), этап 19 (PR #4),
Larastan (PR #5); PR #3–#5 слиты на GitHub merge-коммитом. Промежуточные
`stage*`-ветки и резервная `backup/stage6-contracts` (ранний вариант этапа 06,
в `master` не входил, последний коммит `b5f9419`) удалены. Сейчас в
репозитории только `master`.
