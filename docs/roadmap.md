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
| 02 | MockAdapter | stages/stage-02-mock-adapter.md | not started | 01 |
| 03 | Widgets (Chart.js) | stages/stage-03-widgets.md | not started | 02 |
| 04 | Presentation (Standalone/Iframe) | stages/stage-04-presentation.md | not started | 03 |
| 05 | Bitrix24Adapter | — | not planned (ждёт клиента) | 01 |
| 06 | OneCAdapter | — | not planned (ждёт клиента) | 01 |
