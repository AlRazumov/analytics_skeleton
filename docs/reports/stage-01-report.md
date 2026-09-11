# Отчёт: Этап 01 — Core-модели и контракт DataSourceAdapter

## Итог
Этап выполнен полностью, все чекбоксы в
`docs/stages/stage-01-core-models.md` закрыты, статус в
`docs/roadmap.md` обновлён на `done`.

## Созданные файлы

### Domain-модели (app/Core/Domain/)
- `Enums/StockMovementType.php` — string-backed enum (`in`, `out`,
  `transfer`)
- `Deal.php` — readonly DTO
- `Product.php` — readonly DTO
- `StockMovement.php` — readonly DTO
- `DateRange.php` — readonly value object

### Контракт (app/Core/Contracts/)
- `DataSourceAdapter.php` — интерфейс с тремя методами
  (`fetchDeals`, `fetchProducts`, `fetchStockMovements`), докблок
  фиксирует потоковую семантику (один вызов за прогон синхронизации,
  без кеширования состояния между вызовами).

### Staging-слой
Миграции (database/migrations/):
- `2026_09_08_184932_create_staging_products_table.php`
- `2026_09_08_184933_create_staging_deals_table.php`
- `2026_09_08_184934_create_staging_stock_movements_table.php`

Все три поля/индексы соответствуют спецификации из stage-файла,
включая составные индексы `(product_external_id, occurred_at)` на
`staging_deals` и `staging_stock_movements`, и `unique` на
`staging_products.external_id`. `type` в `staging_stock_movements`
хранится строкой (значение `StockMovementType->value`), без native
Postgres enum.

Eloquent-модели (app/Core/Staging/):
- `StagingProduct.php`
- `StagingDeal.php`
- `StagingStockMovement.php`

Только `$fillable`/`$casts` (включая `array` для `meta` и `datetime`
для временных полей), без бизнес-логики. Сервис записи
domain-модель → staging на этом этапе не создавался (не требовался
acceptance criteria).

### Тесты (Pest)
- `tests/Unit/Core/Domain/DealTest.php`
- `tests/Unit/Core/Domain/ProductTest.php`
- `tests/Unit/Core/Domain/StockMovementTest.php`
- `tests/Unit/Core/Domain/DateRangeTest.php`
- `tests/Feature/Core/Staging/StagingModelsTest.php`

## Самостоятельные решения

**Путь для Eloquent-моделей staging: `app/Core/Staging/`.**
Выбран как явно предложенный в stage-файле дефолт, без отклонения от
Laravel-конвенции `app/Models/` — staging-модели логически являются
частью core-хранилища сырых данных (общего для адаптеров), а не
доменной сущностью приложения общего назначения, поэтому размещение
рядом с `Domain/` и `Contracts/` под одним namespace `App\Core\*`
показалось более консистентным с архитектурой из CLAUDE.md.

**Тесты staging-моделей без Laravel factory.** По умолчанию Laravel
резолвит фабрику модели по конвенции `Database\Factories\{Model}Factory`
относительно имени класса, что не работает "из коробки" для моделей
вне `app/Models/` без переопределения `newFactory()`. Поскольку
stage-файл явно допускает "минимум smoke-тест на создание" как
альтернативу полноценной фабрике, использовано прямое
`Model::create()` в Feature-тестах с `RefreshDatabase` — это проще и
не требует лишней инфраструктуры ради одного этапа.

## Результат делегированной проверки (opencode-bridge)
Задача: найти в `app/Core/` вхождения строк `bitrix`, `iblock`, `crm`,
`1c`, `onec`, `б24` (без учёта регистра), а также использования
Eloquent `Model`/`DB`-фасада в `app/Core/Domain/` и
`app/Core/Contracts/`.

Результат: **ничего не найдено** ни по одному из критериев. Domain-
модели и контракт свободны от адаптер-специфичных имён и от
Eloquent/DB-зависимостей, как и требуется архитектурой (core не
зависит от адаптеров).

## Результат тестов
```
./vendor/bin/sail artisan migrate            — 3 новые миграции применены
./vendor/bin/sail artisan migrate:rollback   — откат чистый (down())
./vendor/bin/sail artisan migrate            — повторное применение чистое
./vendor/bin/sail artisan test               — 22 теста, 34 assertions, всё зелёное
```

## Блокеры / неоднозначности
Не возникло. Все архитектурные решения реализованы как зафиксировано
в stage-файле, без пересмотра.

## Изменения после первичной реализации

**2026-09-09, перед началом Этапа 02.** Обнаружена несостыковка:
`StockMovementType::Transfer` уже существовал как значение enum, но
`StockMovement` нёс только одно поле `warehouseId` — физически
невозможно было выразить пару "откуда → куда" для перемещения между
складами. Исправлено:
- `app/Core/Domain/StockMovement.php` — добавлено nullable-поле
  `toWarehouseId`. Семантика задокументирована в докблоках у класса и
  у полей: для `In`/`Out` используется только `warehouseId`,
  `toWarehouseId` остаётся `null`; для `Transfer` `warehouseId` —
  источник (from), `toWarehouseId` обязателен. В конструкторе
  добавлена валидация (`InvalidArgumentException` при нарушении
  инварианта в любую сторону).
- `app/Core/Staging/StagingStockMovement.php` — добавлено
  `to_warehouse_external_id` в `$fillable`, симметрично
  `warehouse_external_id`.
- `database/migrations/2026_09_09_000000_add_to_warehouse_external_id_to_staging_stock_movements_table.php`
  — новая миграция (существующая миграция
  `2026_09_08_184934_create_staging_stock_movements_table.php` не
  переписывалась задним числом), добавляет nullable-колонку
  `to_warehouse_external_id`. up/down проверены.
- Тесты: `tests/Unit/Core/Domain/StockMovementTest.php` дополнен
  кейсами (In/Out без `toWarehouseId` — валидно; `Transfer` без
  `toWarehouseId` — исключение; `Transfer` с `toWarehouseId` —
  валидно); `tests/Feature/Core/Staging/StagingModelsTest.php`
  дополнен кейсом создания записи с `to_warehouse_external_id`.
  `Product` не затронут — привязки к складу там нет и не появилась.

Не вводилась отдельная доменная сущность `Location`/`Warehouse` — не
обоснована текущими задачами, строковых `warehouseId`/`toWarehouseId`
достаточно.

## Следующий шаг
Этап 02 (MockAdapter) не начинался, как и было указано в задаче.
