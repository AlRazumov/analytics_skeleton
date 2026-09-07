# Отчёт: Этап 00

## Статус
Этап **завершён**. Roadmap обновлён на `done`.

## Что было заблокировано в прошлый раз
Изначально планировалось поднять Laravel-проект локально через
`composer create-project laravel/laravel`. Инсталлятор доехал до шага
`php artisan migrate`, но упал с ошибкой:

```
could not find driver (Connection: sqlite, Database: database/database.sqlite, ...)
```

На машине не был установлен ни один PDO-драйвер (`pdo_sqlite`,
`pdo_mysql`, `pdo_pgsql`), а `sudo` для их установки был недоступен без
пароля. По итогам первого прогона было решено ставить Laravel через
Sail (Docker), где драйверы уже есть внутри контейнера.

## Как разрешилось в этом прогоне

### Установка Laravel + Sail
1. `CLAUDE.md` и `docs/` временно вынесены в `/tmp/analytics-skeleton-docs-backup/`,
   `.git` не трогался.
2. Установка через `curl -s "https://laravel.build/analytics-skeleton?with=pgsql" | bash`.
   Инсталлятор создал вложенную директорию `analytics-skeleton/` внутри
   репозитория (как и предполагалось в промпте) и не смог доехать до
   конца (`sail up`/финальный `chmod` от `sudo`) — не хватило TTY для
   пароля. Содержимое вложенной директории и файлы Laravel-скелета
   внутри репозитория оказались владельцем `root` (создавались из
   докер-контейнера), из-за чего `mv`/`chown` от обычного пользователя
   не работали и passwordless `sudo` не было. Обошлись без
   хостового `sudo`: ownership поправлен через одноразовый
   `docker run --rm -v "$PWD":/work alpine chown -R $(id -u):$(id -g) /work`.
   После этого содержимое вложенной директории перенесено на уровень
   выше, пустая директория и её собственный вложенный `.git` (созданный
   Laravel-инсталлятором) удалены — в репозитории остался только
   исходный `.git`.
3. **Конфликт CLAUDE.md**: Laravel-скелет (Laravel Boost bootstrap)
   создал свой `CLAUDE.md` поверх места, куда нужно было вернуть
   документацию проекта. По явному указанию пользователя: Laravel-версия
   перемещена в `docs/laravel-conventions.md`, документация проекта
   восстановлена из бэкапа в корневой `CLAUDE.md`, в конце которого
   добавлен раздел со ссылкой на `docs/laravel-conventions.md`.
4. `vendor/` для самого проекта не был реально установлен (нестыковка
   путей внутри инсталлятор-контейнера — composer install фактически
   выполнился не в смонтированную директорию). Дозаполнено вручную:
   `composer install`, затем `composer require laravel/sail --dev` и
   `php artisan sail:install --with=pgsql` — через одноразовые
   `docker run` с образом `laravelsail/php84-composer`, без хостового
   PHP/Composer (локально нет нужных расширений — тот же блокер, что и
   в прошлый раз). `APP_KEY` сгенерирован тем же способом.
5. Сеть до Docker Hub / Packagist в среде оказалась нестабильной
   (периодические `curl error 28`, `DeadlineExceeded` при пуле базовых
   образов) — несколько шагов (`composer install`, `composer require`,
   `sail build`, `sail up`) потребовали повторных попыток, которые в
   итоге прошли успешно.

### Sail
`./vendor/bin/sail up -d` — контейнеры `laravel.test` (образ
`sail-8.5/app`, собран локально из `vendor/laravel/sail/runtimes/8.5`)
и `pgsql` (`postgres:18-alpine`) стартовали и перешли в `Running` /
`healthy`.

### Архитектурные директории
Созданы `app/Core/`, `app/Adapters/`, `app/Widgets/`, `app/Presentation/`,
в каждой — `.gitkeep`.

### Pest
- `composer require pestphp/pest --dev --with-all-dependencies`
- Дополнительно потребовался `pestphp/pest-plugin-laravel` — в Pest 4
  команды `artisan pest:install` не существует без этого плагина
  (в промпте предполагалась старая команда `artisan pest:install`,
  по факту актуальный способ — `./vendor/bin/sail pest --init`).
- `./vendor/bin/sail pest --init` создал `tests/Pest.php` (остальные
  файлы тестовой директории уже существовали в скелете Laravel).
- `./vendor/bin/sail artisan test` — дефолтные тесты проходят:
  `{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":2}`

### Миграция metrics_snapshots
Создана `database/migrations/2026_09_07_181506_create_metrics_snapshots_table.php`:
- `entity_type` — string
- `entity_id` — string (полиморфный, под внешние строковые ID из Б24/1С)
- `metric_key` — string
- `value` — `decimal(20,4)`
- `value_meta` — `jsonb`, nullable (недоскалярный довесок, не основное
  хранилище значения)
- `period` — string; формат (`'YYYY-MM'` для месяцев, `'YYYY-MM-DD'`
  для дней) задокументирован в комментарии миграции, в БД не валидируется
- `timestamps`
- составной индекс `metrics_snapshots_entity_metric_period_index` по
  `(entity_type, entity_id, metric_key, period)`

Eloquent-модель не создавалась (по условию промпта).

`php artisan migrate` — накатилась чисто (вместе со стандартными
миграциями Laravel: users, cache, jobs).
`php artisan migrate:rollback` — откатилась чисто, без ошибок.
После проверки миграции повторно накачены (`migrate`), чтобы оставить
БД в рабочем состоянии.

## Версии окружения (из контейнеров Sail)
- Laravel Framework: 13.30.1
- PHP: 8.5.10 (cli)
- PostgreSQL: 18.6 (Alpine, образ `postgres:18-alpine`)

## Git
Один коммит на этапе: Laravel + Sail + структура директорий +
миграция (см. `git log`). `.env` и прочие файлы по стандартному
`.gitignore` Laravel в staging не попали — проверено `git status` /
`git check-ignore` перед коммитом. Правки документации (roadmap,
stage-00, этот отчёт) коммитятся отдельно, не смешивая с кодом.

## Открытые решения по типам полей (из предыдущего частичного отчёта)
Зафиксированы пользователем при постановке задачи на этот прогон и
использованы как есть:
- `entity_id` — `string` (как и предполагалось).
- `value` — `decimal(20,4)` для скаляра + отдельное поле `value_meta`
  (`jsonb`, nullable) для нескалярного довеска. Это самостоятельное
  решение, добавленное в промпт этого прогона, а не то, что было
  предложено в предыдущем отчёте (там рассматривался выбор
  decimal-vs-json для одного поля `value`).
- `period` — `string`, формат зафиксирован только в комментарии
  к миграции.

## Результат
Все acceptance criteria этапа 00 выполнены. Этап 01 не начинался.
