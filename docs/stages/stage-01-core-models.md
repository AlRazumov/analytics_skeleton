# Этап 01: Core-модели и контракт DataSourceAdapter

## Цель
Заложить границу core/adapters: domain-модели без привязки к источнику
данных, потоковый контракт DataSourceAdapter, staging-слой для
персистентности сырых данных за один проход синхронизации. Расчётная
логика (ABC/XYZ/оборачиваемость) в этот этап НЕ входит — только модели,
контракт и хранилище под сырые данные.

## Архитектурные решения (зафиксированы, не пересматривать в рамках
этого этапа без явного обсуждения)
- Domain-модели (Deal, Product, StockMovement) — plain PHP readonly
  классы, НЕ Eloquent. Это структуры для передачи данных между adapter
  и staging-слоем, не персистентные сущности.
- entity_id/внешние ID везде — string (тот же принцип, что и в
  metrics_snapshots на Этапе 00).
- DataSourceAdapter — потоковый контракт: методы возвращают iterable,
  вызываются один раз за прогон синхронизации (не для каждого расчёта
  отдельно). Реализация не должна кешировать состояние между вызовами.
- Staging: отдельная таблица под каждую известную сущность
  (staging_deals, staging_products, staging_stock_movements), НЕ единая
  JSONB-таблица. Staging-модели — обычные Eloquent (это уже
  персистентный слой, не domain).
- StockMovement.type — PHP 8 enum (StockMovementType: In, Out,
  Transfer), не строка. В БД (staging_stock_movements.type) хранится
  как обычная string-колонка со значением StockMovementType->value —
  НЕ Postgres native enum тип, чтобы миграции оставались просто
  обратимыми и добавление нового case не требовало ALTER TYPE.
- metrics_snapshots (Этап 00) остаётся единственным местом, где
  оправдан JSONB (value_meta) как основное значение — там открытый
  набор metric_key. Staging-таблицы могут иметь nullable jsonb `meta`
  под источник-специфичный "хвост", но это не основное хранилище
  данных, а типизированные колонки — основное.
- Названия колонок в staging — external_id / product_external_id /
  warehouse_external_id (не id / product_id), чтобы визуально отличать
  staging (где id всегда внешний) от возможных будущих внутренних
  сущностей.

## Подэтапы

### 1. Domain-модели
- [x] app/Core/Domain/Enums/StockMovementType.php — enum: In, Out,
      Transfer
- [x] app/Core/Domain/Deal.php — readonly DTO: id (string), productId
      (string), amount (float), date (DateTimeImmutable), meta (array,
      default [])
- [x] app/Core/Domain/Product.php — readonly DTO: id (string), name
      (string), category (string, nullable), meta (array, default [])
- [x] app/Core/Domain/StockMovement.php — readonly DTO: id (string),
      productId (string), warehouseId (string), quantity (float), type
      (StockMovementType), date (DateTimeImmutable), meta (array,
      default [])
- [x] app/Core/Domain/DateRange.php — readonly value object: start,
      end (DateTimeImmutable), используется в сигнатурах
      fetchDeals/fetchStockMovements
- [x] Явно НЕ включать в эти классы ничего специфичного для Bitrix/1С
      (проверка — см. Делегирование ниже)

### 2. Контракт DataSourceAdapter
- [x] app/Core/Contracts/DataSourceAdapter.php — интерфейс:
      - fetchDeals(DateRange $period): iterable (@return iterable<Deal>)
      - fetchProducts(): iterable (@return iterable<Product>)
      - fetchStockMovements(DateRange $period): iterable
        (@return iterable<StockMovement>)
- [x] Докблок интерфейса явно документирует: метод вызывается один раз
      за прогон синхронизации, реализация не кеширует состояние между
      вызовами

### 3. Staging-слой (миграции + Eloquent-модели)
- [x] Миграция staging_deals: external_id (string, indexed),
      product_external_id (string), amount (decimal 20,4), occurred_at
      (timestamp), meta (jsonb, nullable), synced_at (timestamp),
      timestamps
- [x] Миграция staging_products: external_id (string, unique indexed),
      name (string), category (string, nullable), meta (jsonb,
      nullable), synced_at, timestamps
- [x] Миграция staging_stock_movements: external_id (string, indexed),
      product_external_id (string), warehouse_external_id (string),
      quantity (decimal 20,4), type (string — хранит
      StockMovementType->value), occurred_at (timestamp), meta (jsonb,
      nullable), synced_at, timestamps
- [x] Индексы: составной (product_external_id, occurred_at) на
      staging_deals и staging_stock_movements
- [x] Eloquent-модели StagingDeal, StagingProduct,
      StagingStockMovement (app/Core/Staging/ или app/Models/ — выбери
      по Laravel-конвенции проекта, обоснуй в отчёте если отклоняешься
      от app/Core/Staging/) — только $fillable/casts, без бизнес-логики
- [x] НЕ создавать сервис записи domain-модель → staging на этом этапе
      (не обязательно для acceptance criteria; если тривиально
      добавляется — можно, но не более одного простого класса)

## Acceptance criteria
- [x] Все domain-модели — readonly классы, не наследуют Eloquent
      Model, не содержат SQL/query-логики
- [x] Проверка на отсутствие bitrix/1С-специфичных имён/полей в
      app/Core/ (см. Делегирование)
- [x] DataSourceAdapter — интерфейс, без реализаций (MockAdapter —
      Этап 02)
- [x] Все три staging-миграции накатываются и откатываются чисто
      (up/down)
- [x] Тесты (Pest): для каждой domain-модели — тест на конструирование
      и неизменяемость (readonly, попытка мутации кидает ошибку); для
      staging-моделей — тест что миграция применяется без ошибок и
      запись создаётся через factory (полноценный factory не
      обязателен, если избыточен — минимум smoke-тест на создание)
- [x] ./vendor/bin/sail artisan test — зелёный

## Делегирование
- Дешёвой модели (через opencode-bridge, direct delegate_to_opencode
  или context-gatherer): после написания domain-моделей и контракта —
  найти в app/Core/ все вхождения строк bitrix, iblock, crm, 1c, onec,
  б24 (без учёта регистра), а также все использования Eloquent Model /
  DB-фасада внутри app/Core/Domain/ и app/Core/Contracts/. Вернуть
  только список файл:строка, без содержимого файлов целиком.
- Дирижёр сам: типы полей, структура staging, сигнатура контракта —
  уже решены выше, не пересматривать самостоятельно, только
  реализовывать. Если находишь основания для пересмотра — остановись
  и опиши в отчёте, не меняй решение молча.

## Зависимости
Этап 00 — done.
