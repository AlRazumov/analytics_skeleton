# Этап 17: продавцы (Seller) на моке

Зависит от этапов 06 и 13. Реальные адаптеры (15/16) не создаются: данных
по продавцам от 1С пока нет, делается всё, что от них не зависит.

## Что делаем

- Core: `Seller`, `Deal::$sellerId`, `DataSourceAdapter::fetchSellers()` и
  `sellerCoverage()` (full/partial/none), справочник `staging_sellers`,
  `staging_deals.seller_external_id` (nullable).
- Реестр метрик продавцов (`analytics.metrics.seller`, включённые —
  `analytics.enabled_metrics.seller`): sales_count, sales_amount, avg_check,
  share_of_total, sales_per_active_day, trend. Продажи без продавца — под
  `__none__`.
- MockAdapter: 12/24/48 продавцов, уволенный, новичок, сделки без продавца,
  три режима покрытия.
- Обобщённый виджет `<x-widgets.top-n>` (таблица + bar chart), блок на обзоре.

## Что не входит

OneCAdapter/Bitrix24Adapter, SellerResolver для 1С, UI управления метриками,
метрики сверх перечисленных, экспорт.

Детали и результаты — `docs/reports/stage-17-sellers.md`.
