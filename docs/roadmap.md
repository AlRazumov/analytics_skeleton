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
| 06 | Bitrix24Adapter | — | not planned (ждёт клиента) | 01 |
| 07 | OneCAdapter | — | not planned (ждёт клиента) | 01 |

## TODO before real adapters

Пункты, которые нужно пересмотреть перед стартом этапов 06/07 (реальные,
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
