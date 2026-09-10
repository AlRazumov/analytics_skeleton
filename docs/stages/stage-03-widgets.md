# Этап 03: Widgets (Chart.js)

Слой визуальных компонентов, читающий данные исключительно через
`metrics_snapshots` (не напрямую из adapters). `widgets` не знает о
Bitrix24/1С — только о core-моделях и агрегированных метриках.

## Состав

- `app/Core/Widgets/DTO/*` — readonly DTO без Eloquent и без
  источник-специфичных полей: `SeriesPoint`, `Series`, `LineChartData`
  (переиспользуется для line и bar chart), `TableData`, `KpiCardData`,
  `MatrixCellData`, `MatrixData`, `MetricsSnapshotRecord` (форма строки
  `metrics_snapshots`, не Eloquent-модель).
- `app/Core/Domain/Period.php` + `Enums/PeriodGranularity.php` — диапазон
  дат с гранулярностью (month/day), умеет перечислять ключи
  `metrics_snapshots.period` и сдвигаться на год назад (YoY).
- `app/Core/Widgets/Contracts/MetricsSnapshotRepository.php` — контракт
  чтения снэпшотов; core знает только о нём.
- `app/Core/Widgets/WidgetDataProvider.php` — по методу на тип виджета:
  `lineChart`, `lineChartYoY`, `table`, `kpiCard`, `abcXyzMatrix`.
- `app/Repositories/EloquentMetricsSnapshotRepository.php` +
  `app/Models/MetricsSnapshot.php` — реализация контракта вне core,
  забинжена в `AppServiceProvider`.
- `resources/views/components/widgets/*.blade.php` — line-chart,
  bar-chart (Chart.js через `@json`), table, kpi-card, matrix (чистый
  Blade). Каждый принимает ровно один DTO-проп `data`.
- `resources/views/demo/widgets.blade.php` + `DemoWidgetsController` +
  маршрут `/demo/widgets` — демо-страница со всеми 5 виджетами.
- `database/seeders/DemoMetricsSnapshotSeeder.php` — наполняет
  `metrics_snapshots` данными из `MockAdapter` для демо (не часть
  расчётного пайплайна, инфраструктурная склейка для проверки виджетов).

Зависимости: 02 (MockAdapter — используется только seeder'ом демо-страницы,
`widgets`/`WidgetDataProvider` от него не зависят).

См. отчёт: `docs/reports/stage-03-report.md`.
