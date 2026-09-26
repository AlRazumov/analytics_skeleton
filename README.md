# analytics-skeleton

Переиспользуемое расчётное ядро аналитики продаж и остатков для двух будущих
продуктов: BI для 1С и дашборды для Bitrix24. Ядро не знает об источниках
данных: вся интеграция — в адаптерах, реализующих `DataSourceAdapter`.
Сейчас есть только `MockAdapter` (детерминированные демо-данные); реальные
адаптеры ждут клиента.

## Что умеет

- Метрики в `metrics_snapshots`: выручка, ABC/XYZ, оборачиваемость, неликвиды,
  дни до обнуления, потерянные продажи, метрики продавцов.
- Дашборды (вход по логину): обзор продаж, ABC/XYZ, остатки, топ товаров,
  оборачиваемость, рекомендации перемещений между складами, продавцы с
  CSV-выгрузкой.
- `adapter:check` — проверка источника данных на контракт адаптера.

## Быстрый старт (демо на моке)

```bash
composer install                  # или через docker, если локально нет PHP — см. документацию Sail
cp .env.example .env              # DB_* уже под Sail/PostgreSQL (см. docs/demo.md)
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan demo:install --email=demo@example.com   # пароль спросит интерактивно
```

Затем войти на `/login`. Подробнее — [docs/demo.md](docs/demo.md).

## Документация

| Документ | О чём |
|----------|-------|
| [CLAUDE.md](CLAUDE.md) | архитектура, правила работы, тесты, стек |
| [docs/roadmap.md](docs/roadmap.md) | этапы, статус, известные проблемы |
| [docs/adapter-contract.md](docs/adapter-contract.md) | что обязан делать адаптер источника; `adapter:check` |
| [docs/demo.md](docs/demo.md) | демо: профили мока, страницы |
| [docs/features.md](docs/features.md) | флаги функциональности и пороги |
| [docs/security.md](docs/security.md) | аутентификация, пользователи |
| `docs/stages/`, `docs/reports/` | описания этапов и отчёты по ним |

## Проверки

```bash
./vendor/bin/sail artisan test                                   # все тесты
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G
./vendor/bin/pint --test
```
