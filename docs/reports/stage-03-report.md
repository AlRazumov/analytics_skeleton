# Отчёт: этап 03 — Widgets (Chart.js)

## Что реализовано

**DTO** (`app/Core/Widgets/DTO/`) — все readonly, PHP 8.3, без Eloquent,
без Bitrix/1С-специфичных полей: `SeriesPoint`, `Series`, `LineChartData`
(переиспользуется для line и bar), `TableData`, `KpiCardData`,
`MatrixCellData`, `MatrixData`. Отдельно `MetricsSnapshotRecord` — форма
строки `metrics_snapshots`, используемая контрактом чтения (тоже DTO, не
Eloquent-модель).

**WidgetDataProvider** (`app/Core/Widgets/WidgetDataProvider.php`) —
метод на тип виджета: `lineChart`, `lineChartYoY`, `table`, `kpiCard`,
`abcXyzMatrix`. Единственная зависимость — контракт
`MetricsSnapshotRepository`; импортов из `adapters/` нет (проверено
вручную и грепом).

**Blade-компоненты** (`resources/views/components/widgets/`) —
`line-chart`, `bar-chart` (Chart.js 4 с CDN, данные через `@json`),
`table`, `kpi-card`, `matrix` (чистый Blade, без JS). Каждый принимает
ровно один проп `data` — соответствующий DTO.

**Демо-страница** — `/demo/widgets` (`DemoWidgetsController` +
`resources/views/demo/widgets.blade.php`), собирает все 5 виджетов.
Данные наполняются `DemoMetricsSnapshotSeeder` (агрегирует revenue по
товару/месяцу из `MockAdapter`, плюс демо-ABC/XYZ-классификация).
Проверено вручную через `sail`: `php artisan db:seed
--class=Database\Seeders\DemoMetricsSnapshotSeeder`, страница отдаёт
200 и рендерит все 5 виджетов без ошибок (KPI, line, bar, table,
matrix — с реальными числами).

## Решения сверх ТЗ

- **`Period` (core/Domain) + `PeriodGranularity`.** В ТЗ методы
  провайдера принимают `Period $period`, но `metrics_snapshots.period`
  — одиночный строковый ключ (`'YYYY-MM'`/`'YYYY-MM-DD'`), а не
  диапазон. Поэтому `Period` — диапазон дат с гранулярностью, который
  умеет: `keys(): string[]` (список снэпшот-ключей, покрывающих
  диапазон — это и даёт точки для line chart) и `previousYear(): self`
  (для `lineChartYoY`). Без этого метод не смог бы вернуть более одной
  точки на серию.
- **`MetricsSnapshotRepository` — контракт в core, реализация вне.**
  ТЗ прямо требует "провайдер не импортирует ничего из adapters/", но
  Eloquent тоже не должен быть в core (правило проекта: core не
  привязан к конкретной инфраструктуре). Контракт лежит в
  `core/Widgets/Contracts/`, Eloquent-реализация
  (`EloquentMetricsSnapshotRepository` + модель `MetricsSnapshot`) — в
  `app/Repositories/` и `app/Models/`, забинжена в `AppServiceProvider`.
- **`abcXyzMatrix` — фиксированные `entity_type`/`metric_key`.** ТЗ не
  передаёт их параметрами метода (в отличие от остальных виджетов) —
  трактовал это так, что матрица не срез по произвольной метрике, а
  конкретный виджет ABC/XYZ-классификации товаров. Значения
  зафиксированы константами в провайдере (`entity_type='product'`,
  `metric_key='abc_xyz_classification'`), классы читаются из
  `value_meta` (`abc_class`, `xyz_class`).
- **`kpiCard` — необязательный `$unit`.** В ТЗ `KpiCardData.unit` есть
  в DTO, но метод его не принимал явно — добавил опциональный параметр,
  иначе передать unit было бы неоткуда.
- **`DemoMetricsSnapshotSeeder`.** Расчётного пайплайна
  adapters → metrics_snapshots в проекте ещё нет (это не входит ни в
  один текущий этап), а демо-странице нужны реальные снэпшоты. Seeder —
  инфраструктурная склейка только для `/demo/widgets`: детерминированно
  агрегирует revenue по (товар, месяц) из `MockAdapter::fetchDeals()` и
  раскладывает товары по ABC (доля выручки) / XYZ (хэш id, не настоящий
  расчёт вариации спроса — для демо не нужен). Business-логики в
  core/widgets он не добавляет.

## Что не сделано / вне рамок этапа

- Настоящий расчётный пайплайн, наполняющий `metrics_snapshots` из
  adapters (агрегация ABC/XYZ по реальной статистике продаж, расчёт
  дельт и т.п.) — это следующий уровень (пока не выделен отдельным
  этапом в roadmap; появится, когда будет ясна методология расчёта).
  `DemoMetricsSnapshotSeeder` — не замена ему, только для визуальной
  проверки виджетов.
- Фильтрация/пагинация в `table`-виджете, кастомизация цветов/тем
  Chart.js — не запрашивались ТЗ, не добавлял.
- Автотесты у Blade-компонентов не добавлялись (только у
  `WidgetDataProvider`, unit, с фейковым репозиторием) — приёмка визуала
  прошла вручную через `/demo/widgets`.
