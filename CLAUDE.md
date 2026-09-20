# analytics-skeleton

Переиспользуемое расчётное ядро аналитики (core + adapters) для двух
продуктов: BI для 1С и дашборды для Bitrix24. Ядро не знает о конкретных
источниках данных — вся интеграционная специфика инкапсулируется в
адаптерах.

## Архитектура

```
app/Core/               — доменные модели, контракты, бизнес-логика расчётов
app/Core/Widgets/       — виджеты (контракты, DTO, WidgetDataProvider);
                          работают только через core-модели, не знают
                          об адаптерах
app/Adapters/           — реализации DataSourceAdapter под конкретные
                          источники (MockAdapter, Bitrix24Adapter,
                          OneCAdapter)
app/Http/Controllers/Dashboards/ — presentation-контроллеры
                          (способы встраивания/показа: Standalone,
                          Iframe)
resources/views/components/layouts/ — Blade-layout'ы presentation-слоя
                          (standalone.blade.php и т.п.)
```

Виджеты и presentation-контроллеры физически лежат внутри `app/Core` и
`app/Http`, а не в отдельных верхнеуровневых namespace'ах `widgets/` /
`presentation/` — так сложилось на этапах 03/05 (см. `docs/reports/
stage-03-report.md`). Выделение отдельных namespace'ов — решение,
которое имеет смысл принимать только когда появятся реальные адаптеры
(Bitrix24Adapter/OneCAdapter, этапы 13/14 roadmap) и будет видно, нужна ли такая
изоляция.

Ключевой принцип: `core` определяет контракт `DataSourceAdapter`,
адаптеры под конкретные продукты его реализуют. `widgets` и
`presentation` строятся поверх `core` и не должны напрямую зависеть от
конкретного адаптера. Это позволяет переиспользовать один и тот же
движок для 1С и Bitrix24, различаясь только слоем адаптера.

Хранилище агрегированных метрик — таблица `metrics_snapshots`
(entity_type, entity_id, metric_key, value, period), общая для всех
источников.

## Стек

- Laravel + Pest (тестовый раннер)
- Chart.js для виджетов

## Roadmap

Список этапов разработки, их статус и зависимости — см.
[docs/roadmap.md](docs/roadmap.md). Детальное описание каждого этапа —
в `docs/stages/stage-NN-*.md`.

Отчёты по завершённым этапам — в `docs/reports/`.

## Правила работы над проектом

- Новые модули или источники данных добавляются новым этапом
  (новая строка в roadmap + новый файл `stage-NN-*.md`), существующие
  файлы этапов не переписываются задним числом.
- Не начинать этап, пока не выполнены зависимости, указанные в
  roadmap.
- Каждый этап должен заканчиваться отчётом в `docs/reports/`.
- `core` не должен содержать зависимостей на конкретные адаптеры;
  адаптеры не должны содержать бизнес-логики, которая должна жить в
  `core`.

## Источник данных и справочники

Источник выбирается `ANALYTICS_SOURCE` (сейчас только `mock`; профиль и seed
мока — `ANALYTICS_MOCK_PROFILE`, `ANALYTICS_MOCK_SEED`). Адаптер создаёт
только `app/Adapters/DataSourceAdapterFactory`, контейнер отдаёт
`DataSourceAdapter` через неё. Справочники товаров и складов копируются в БД
командой `reference:sync` (и автоматически перед `metrics:calculate`);
веб-страницы берут названия из БД и к адаптеру не обращаются.

## Тесты

Тесты запускаются в Sail (тестовая БД `testing`, dev-БД не используется):

```bash
./vendor/bin/sail artisan test                       # всё, включая медленные — эталонный прогон
./vendor/bin/sail artisan test --exclude-group=slow  # быстрый прогон без Medium-профиля
./vendor/bin/sail artisan test --group=slow          # только медленные
```

Медленные интеграционные тесты на Medium-профиле мока помечены
`->group('slow')` (Pest). Полный прогон без исключений остаётся эталоном
перед коммитом этапа; исключение группы — только для быстрой обратной связи.

Флаги функциональности (показ страниц/виджетов) — см.
[docs/features.md](docs/features.md).

## Безопасность

Что закрыто аутентификацией, что нет, и как создать пользователя — см.
[docs/security.md](docs/security.md).

## Laravel-специфичные гайдлайны

Автосгенерированные Laravel Boost конвенции — см.
docs/laravel-conventions.md.
