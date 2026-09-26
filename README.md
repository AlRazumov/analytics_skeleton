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

## Развёртывание без Sail (nginx + php-fpm)

Сейчас источник данных только один — мок (`ANALYTICS_SOURCE=mock`), так что
такая инсталляция — демо-стенд на синтетических данных. Порядок ниже рассчитан
и на будущие реальные адаптеры.

### Что нужно на сервере

- PHP ≥ 8.4.1 (CI и Sail — 8.5), CLI и FPM, с расширениями `pdo_pgsql`,
  `mbstring`, `xml`/`dom`, `ctype`, `fileinfo`, `tokenizer`, `openssl`,
  `iconv`; проверка — `composer check-platform-reqs --no-dev`;
- PostgreSQL 18 (только PostgreSQL: в запросах и миграциях есть
  Postgres-специфичный SQL);
- Composer, nginx, cron.

Node/npm не нужны: фронтенд не собирается, Chart.js лежит в
`public/vendor/chartjs`. Воркер очередей не нужен: фоновых задач нет. Сессии
и кэш хранятся в БД (таблицы создаются миграциями).

### Установка

```bash
# база и пользователь (от postgres)
sudo -u postgres createuser -P analytics
sudo -u postgres createdb -O analytics analytics

# код — например, в /var/www/analytics, владелец — пользователь деплоя
git clone https://github.com/AlRazumov/analytics_skeleton.git /var/www/analytics
cd /var/www/analytics
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

В `.env` поправить:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://analytics.example.com
SESSION_SECURE_COOKIE=true      # только по HTTPS

DB_HOST=127.0.0.1
DB_DATABASE=analytics
DB_USERNAME=analytics
DB_PASSWORD=...

LOG_STACK=daily                 # логи по дням в storage/logs, не один растущий файл

# источник данных и мок — см. docs/demo.md, флаги — docs/features.md
ANALYTICS_SOURCE=mock
ANALYTICS_MOCK_PROFILE=small    # small | medium
```

Дальше:

```bash
php artisan key:generate
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

# php-fpm (www-data) пишет в storage и bootstrap/cache
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

php artisan metrics:calculate            # первый расчёт (заодно синхронизирует справочники)
php artisan users:create anna@example.com --name="Анна"   # пароль спросит интерактивно
```

На моке вместо `metrics:calculate` можно запустить `demo:install`: он посчитает
всю историю мока, в том числе второй год на `medium`, чтобы было сравнение
«год к году». Пользователи и смена пароля описаны в
[docs/security.md](docs/security.md).

### nginx

```nginx
server {
    listen 443 ssl;
    server_name analytics.example.com;
    root /var/www/analytics/public;          # только public/, не корень проекта

    ssl_certificate     /etc/letsencrypt/live/analytics.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/analytics.example.com/privkey.pem;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;   # сокет вашей версии PHP
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

server {
    listen 80;
    server_name analytics.example.com;
    return 301 https://$host$request_uri;
}
```

Если перед nginx есть ещё прокси или балансировщик, настройте trusted proxies,
иначе лимитер входа будет считать всех клиентов одним IP. Подробнее —
[docs/security.md](docs/security.md), §5.

### Регулярный пересчёт метрик (cron)

Страницы читают только `metrics_snapshots` и справочники в БД, к источнику
данных не обращаются. Данные обновляются только командой `metrics:calculate`.
Без `--period` она пересчитывает 12 месяцев: у мока — по конец его истории, у
реального источника — по текущий месяц. Перед расчётом команда синхронизирует
справочники.

```cron
# /etc/cron.d/analytics — каждую ночь в 03:00, от пользователя, которому принадлежат storage/
0 3 * * * deploy cd /var/www/analytics && flock -n storage/metrics.lock php -d memory_limit=512M artisan metrics:calculate >> storage/logs/metrics.log 2>&1
```

- `flock -n` не даёт запустить второй расчёт, пока идёт первый.
- Лимит памяти нужен явно: на профиле `medium` расчёт не укладывается в 128M
  (стандартный лимит FPM-сборок), с 512M занимает меньше минуты.
- Расчёт идёт в транзакции: если он упадёт, на страницах останутся прежние
  данные.
- Планировщик Laravel (`schedule:run`) не используется, задач в нём нет.

### Обновление

```bash
cd /var/www/analytics
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.5-fpm          # сбросить OPcache
php artisan up
```

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
