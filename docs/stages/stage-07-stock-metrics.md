# Этап 07: метрики остатков — неликвиды и дни до обнуления

Зависит от этапа 06 (контракты движений/остатков, `Period`,
`metrics_snapshots`, MockAdapter с манифестом сценариев). Реальные
адаптеры сдвинуты (сейчас 10/11).

## Что делаем

Две метрики поверх `DataSourceAdapter::fetchStock()` /
`fetchStockMovements()`, оформленные как остальные калькуляторы
(`core/Analytics`, пишут `MetricsSnapshotRecord`, подключены в
`MetricsCalculationService` и `metrics:calculate`).

## Что входит

1. `days_since_last_sale` (entity_type=`product`): дней от последней
   продажи до asOf, для товаров с остатком > 0.
2. `days_of_stock` (entity_type=`product_warehouse`, entity_id
   `<productId>:<warehouseId>`): остаток / средний суточный спрос за
   окно, дни с нулевым остатком исключаются из знаменателя.
3. Пороги/окна — `config/analytics.php`.
4. Метрики не считаются без capabilities StockMovements и
   StockSnapshots (причина — в лог, без исключения).
5. Тесты: формулы на ручных наборах, интеграция на MockAdapter по
   манифесту, идемпотентность `metrics:calculate`.

## Что не входит

PoP, топ/анти-топ, ABC-XYZ матрица, прогноз, алерты, виджеты.

Детали и результаты — `docs/reports/stage-07-stock-metrics.md`.
