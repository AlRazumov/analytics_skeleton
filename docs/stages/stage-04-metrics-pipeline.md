# Этап 04: Расчётный пайплайн (adapters → metrics_snapshots)

Заменяет `DemoMetricsSnapshotSeeder`: настоящий расчётный пайплайн,
наполняющий `metrics_snapshots` из `DataSourceAdapter` — калькуляторы
метрик в `core/Analytics/`, интерфейс записи
`MetricsSnapshotWriter`, artisan-команда `metrics:calculate`.

Детали, допущения и результат — см. `docs/reports/stage-04-report.md`.
