# Этап 09 — оборачиваемость (стартовый остаток) и аутентификация standalone-части

Ветка `stage-turnover-auth` (от `stage-period-comparison`), не запушена, не смержена.
Тесты — только на тестовой БД `testing` в Sail. Dev-БД `laravel` не использовалась вообще
(ни записи, ни read-only). Миграций в этом этапе нет.

## 1. Что сделано

**Часть A — TurnoverCalculator.** Источник opening исправлен: вместо накопленного сальдо от 0 —
реальный остаток `fetchStock(начало диапазона − 1 день)`, суммарно по складам. Формула не менялась
(штуки, `(opening + closing) / 2`, «раз за месяц»). `MetricsCalculationService` делает один вызов
`fetchStock` и передаёт результат калькулятору; без StockSnapshots turnover не считается (warning в лог).

**Часть B — аутентификация.** `laravel/fortify` (v1.39.0, встал на Laravel 13.30.1 без конфликтов), `features = []`;
своя Blade-страница входа в StandaloneLayout; `auth` на standalone-роуты; iframe-роуты не трогались (их пока нет);
команда `users:create`; раздел «Безопасность инсталляции» (`docs/security.md`).

## 2. Сигнатуры

```php
// App\Core\Analytics\TurnoverCalculator
/**
 * @param  iterable<StockMovement>  $movements
 * @param  array<string, float>  $openingStock  productId → остаток на (начало диапазона − 1 день), суммарно по складам
 * @return MetricsSnapshotRecord[]
 */
public function calculate(iterable $movements, DateRange $period, array $openingStock = []): array;
// $openingStock = [] → нулевой стартовый остаток: корректно только если диапазон начинается там, где остаток нулевой (докблок).
// Строки: товары с движениями ∪ товары с openingStock > 0; avgStock <= 0 → строки нет.
```

`value_meta` (формат `value` не менялся — turnover «раз за месяц»):

```json
{"units_sold": 62.0, "avg_stock": 99.0, "opening_stock": 130.0, "closing_stock": 68.0}
```

Пример из задания (opening 130, продано 62, других движений нет): closing 68, avg 99, turnover 62/99 ≈ 0.6263 (проверено тестом).
Пары `transfer_in/transfer_out` в коде пропускаются (дельта 0): на уровне товара в сумме они дают 0, и результат не зависит от того,
пришли ли обе ножки перемещения (тест с одной «потерянной» ножкой).

```php
// App\Console\Commands\CreateUser
protected $signature = 'users:create {email : Email нового пользователя} {--name= : Имя (по умолчанию — часть email до @)}';
// Пароль: $this->secret('Пароль (минимум 12 символов)') + $this->secret('Повторите пароль'); аргументом/опцией не принимается.
// Валидация: email:rfc + unique(users.email) (до запроса пароля), пароль min 12 + confirmed. Только создаёт, существующих не трогает.
// Email хранится в нижнем регистре (Fortify lowercase_usernames=true при входе).
```

## 3. Turnover до / после

Те же три измерения, что в аудите этапа 08, август 2026, seed 1. Эталон = продано / ((fetchStock(31.07) + fetchStock(31.08)) / 2) по товарам
с avg > 0. «Строк» — число строк за август / число строк эталона. Отношение считается по товарам, где эталон > 0.
(Эталон здесь включает и строки с нулевой оборачиваемостью, поэтому число строк эталона 50/500, а не 48/495 как в аудите, где считались
только товары с продажами.)

| Измерение | до: строк | до: отношение к эталону (n; min / median / max) | после: строк | после: отношение (n; min / median / max) |
|---|---|---|---|---|
| Small, один август | 22 / 50 | 22; 1.8326 / 10.8621 / 183.0000 | 50 / 50 | 48; 1.0000 / 1.0000 / 1.0000 |
| Small, весь год | 50 / 50 | 48; 1.0000 / 1.0000 / 1.0000 | 50 / 50 | 48; 1.0000 / 1.0000 / 1.0000 |
| Medium, дефолтные 12 месяцев (из 24) | 250 / 500 | 247; 1.0000 / 4.5745 / 296.0000 | 500 / 500 | 495; 1.0000 / 1.0000 / 1.0000 |

После правки результат не зависит от диапазона расчёта: одинаковые значения и для одного месяца, и для года, и для 12 месяцев из 24
(тесты с допуском 1e-9, Small и Medium; на Medium дополнительно выборка месяцев 2025-09, 2026-01, 2026-08).

## 4. Изменённые существующие тесты

Ожидания не ослаблялись; каждое изменение — из-за новой семантики или расширенного формата:

1. `tests/Unit/Core/Analytics/MetricsCalculationServiceTest.php` — «calls fetchDeals() and fetchStockMovements() exactly once per calculate()».
   Было: ровно один вызов `fetchStockMovements`, на адаптере только с capability StockMovements (сужение, введённое на этапе 07,
   чтобы метрики остатков не участвовали в подсчёте). Теперь turnover требует и StockSnapshots, поэтому на таком адаптере
   `fetchStockMovements` не вызывается вовсе, а при обоих capabilities вызовов больше одного по проекту. Переписан на фактический
   документированный контракт: `fetchDeals` — 1 раз; `fetchStock` и `fetchStockMovements` — по (2 + число месяцев) раз (turnover 1, неликвиды 1,
   дни до обнуления — по одному на месяц); проверено на двухмесячном диапазоне (4 и 4). Регрессионный смысл сохранён: `fetchDeals` по-прежнему ровно один.
2. `tests/Unit/Core/Analytics/TurnoverCalculatorTest.php` — «computes turnover as unitsSold / avg(opening, closing) for a normal month».
   Прежнее `expect($record->valueMeta)->toBe([])` (мета была пустой) заменено на проверку четырёх ключей и точных значений
   (`units_sold` 20, `avg_stock` 40, `opening_stock` 0, `closing_stock` 80). Значения turnover и остальные утверждения не менялись.
   Остальные два прежних теста Turnover не менялись и проходят (default `[]` сохраняет прежнее поведение).
3. `tests/Unit/Adapters/MockStockMetricsTest.php` — «does not compute or fail stock metrics … without the required capabilities».
   Датасет «movements only» раньше ожидал, что turnover считается (неиспользуемый параметр `$expectTurnover`); теперь turnover без StockSnapshots
   не считается — параметр удалён, проверки усилены: во всех трёх случаях нет `turnover`, `days_since_last_sale`, `days_of_stock`, а warning-лог упоминает
   `turnover` и `days_since_last_sale`.
4. `tests/Pest.php` — `fakeAdapter()`: добавлен счётчик `fetchStockCalls` (нужен тесту из п. 1).

Новые тесты: `TurnoverCalculatorTest` (+7: ненулевой стартовый остаток, остаток без движений → 0, нет строк без движений и остатка, приход в середине
диапазона и цепочка opening=closing, avgStock ≤ 0, default `[]`, перемещения), `MockTurnoverTest` (3: независимость от диапазона Small/Medium,
Medium с дефолтным периодом), `StockMetricsCommandTest` (+1: перезапись СТАРЫХ значений turnover без падения на unique-индексе),
`MockStockMetricsTest` (capability, см. п. 3), `Auth/StandaloneAuthTest` (23 с датасетами), `Auth/CreateUserCommandTest` (10 с датасетами).

## 5. Аутентификация

**Роуты «до»** (`php artisan route:list`, 7 шт.):

| Метод и путь | Имя | Класс | Что это |
|---|---|---|---|
| GET `/` | — | closure (`welcome`) | служебный (стартовая страница фреймворка) |
| GET `/dashboards/overview` | `dashboards.overview` | `OverviewDashboardController` | standalone (StandaloneLayout) |
| GET `/dashboards/abc-xyz` | `dashboards.abc-xyz` | `AbcXyzDashboardController` | standalone (StandaloneLayout) |
| GET `/demo/widgets` | — | `DemoWidgetsController` | демо (свой inline-layout, не StandaloneLayout; данные из `metrics_snapshots`) |
| GET `/up` | — | фреймворк | служебный (health check) |
| GET/PUT `/storage/{path}` | `storage.local`, `storage.local.upload` | фреймворк | служебные (диск `local`) |

iframe-роутов нет (IframeLayout отложен). Модель `User` и миграция `users` (вместе с `sessions`) уже были; layout один — `components/layouts/standalone.blade.php`.

**Роуты «после»** (`route:list -v`, 13 шт.):

| Метод и путь | Имя | Middleware | Под auth |
|---|---|---|---|
| GET `/` | — | web | нет (публичный) |
| GET `/dashboards/overview` | `dashboards.overview` | web, auth | **да** |
| GET `/dashboards/abc-xyz` | `dashboards.abc-xyz` | web, auth | **да** |
| GET `/demo/widgets` | — | web, auth | **да** (спорный случай, п. 6.3) |
| GET `/login` | `login` | web, guest:web | нет (гостевой) |
| POST `/login` | `login.store` | web, guest:web, throttle:login | нет (лимит 5/мин на email+IP) |
| POST `/logout` | `logout` | web, auth:web | да |
| GET/POST `/user/confirm-password`, GET `/user/confirmed-password-status` | `password.confirm*` | web, auth:web | да (служебные Fortify, не отключаются флагами) |
| GET `/up`, GET/PUT `/storage/{path}` | — | — | нет (служебные, без изменений) |

**Файлы, добавленные/изменённые в связи с Fortify и входом:**
- `composer.json`, `composer.lock`: `laravel/fortify ^1.39` (v1.39.0) и его 14 транзитивных зависимостей, которых раньше не было: `bacon/bacon-qr-code`,
  `dasprid/enum`, `laravel/passkeys`, `paragonie/constant_time_encoding`, `pragmarx/google2fa`, `spomky-labs/cbor-php`, `spomky-labs/pki-framework`,
  `symfony/polyfill-php81`, `symfony/property-access`, `symfony/property-info`, `symfony/serializer`, `symfony/type-info`, `web-auth/cose-lib`, `web-auth/webauthn-lib`
  (нужны Fortify для 2FA/passkeys, хотя фичи выключены; обновлений уже установленных пакетов нет). `composer` отчитался: security advisories не найдено.
- `config/fortify.php` (опубликован; `features = []`, `home = /dashboards/overview`).
- `app/Providers/FortifyServiceProvider.php` (вид логина + лимитер `login`), `bootstrap/providers.php` (регистрация).
- `resources/views/auth/login.blade.php` (страница входа).
- `resources/views/components/layouts/standalone.blade.php` (проп `guest` — без навигации и без Chart.js CDN на странице входа; кнопка «Выйти» в `.header__user-slot`; CSS формы входа).
- `routes/web.php` (группа `auth`), `app/Console/Commands/CreateUser.php`, `docs/security.md`, ссылка в `CLAUDE.md`.
- Тесты: `tests/Feature/Auth/*`.

## 6. Расхождения с заданием и принятые решения

1. **Лимитер входа Fortify 1.39 не определяется пакетом.** `config/fortify.php` ссылается на лимитер `login`, но без определения `POST /login` падал с 500
   (`Rate limiter [login] is not defined`) — это поймал тест. Определён штатный вариант (как в стабе Fortify): 5 попыток в минуту на пару email + IP,
   в `FortifyServiceProvider`. Поведение подтверждено тестом: шестая попытка (даже с верным паролем) — 429 с `Retry-After`; другой email не блокируется.
2. **iframe-роутов в проекте нет**, поэтому «поведение iframe-роутов не изменилось» проверить нечем; тест фиксирует, что `auth` не повешен глобально
   (публичные `/` и `/up` остаются доступными). Когда появится iframe-группа, ей нужен собственный тест.
3. **Спорный случай `/demo/widgets`.** Это демо со своим inline-layout (не StandaloneLayout), но оно показывает данные из `metrics_snapshots` — оставлять его
   публичным значит раздавать данные без входа. Поставил под `auth`.
4. **`/` (welcome)** — стартовая страница фреймворка без данных — оставлена публичной. «Главная standalone-страница» для редиректа после входа — `/dashboards/overview`.
5. **Layout изменён минимально**, но изменён: без этого нельзя ни выйти, ни показать вход в стиле проекта (проп `guest`, кнопка выхода в уже существующем «слоте пользователя»,
   CSS формы входа встроен в layout — новых frontend-зависимостей и правок Vite/Tailwind нет).
6. **Fortify регистрирует служебные `user/confirm-password*`** даже при `features = []`; они за `auth`, флагами не отключаются. Список 404-роутов в тесте их не включает.
7. **Email в `users:create` приводится к нижнему регистру**, иначе вход не работает у пользователя, созданного с заглавными буквами (Fortify приводит логин к нижнему регистру).
   Дубликат проверяется до запроса пароля.
8. **Turnover без StockSnapshots не считается** (как и требовалось: заведомо неверные числа не пишем); ранее на адаптере только с StockMovements он считался «по-старому».
9. **Окружение прогона по коммитам:** пакет Fortify стоит в `vendor` при прогонах и на коммитах до его добавления (я не переустанавливал `vendor` между checkout'ами);
   на ранних коммитах он не сконфигурирован, и тесты этапа его не касаются.

## 7. Результаты тестов

Команды: `docker compose exec -T laravel.test php artisan test` и `vendor/bin/pint --test` (Sail; хост-PHP 8.3 не подходит). Каждый коммит проверен последовательным checkout:

```
2bb607e docs: этап 09 в roadmap, адаптеры на 10/11                         203 passed (73095 assertions)   Duration 17.35s   Pint PASS 108 files
8fd6665 fix(metrics): turnover — стартовый остаток из fetchStock (A)      214 passed (75751)              Duration 35.67s   Pint PASS 109 files
07e1330 feat(auth): вход/выход для standalone-части (B)                   247 passed (75849)              Duration 37.97s   Pint PASS 114 files
```

Итог на HEAD (после этого коммит отчёта меняет только документацию) — см. последний прогон в конце раздела:

```
$ docker compose exec -T laravel.test php artisan test
Tests:    247 passed (75849 assertions)
$ docker compose exec -T laravel.test vendor/bin/pint --test
PASS   114 files
```

Прогон стал заметно дольше (17 с → 37 с): интеграционные тесты на Medium-профиле мока (симуляция истории на каждый `fetchStock`).

## 8. Замеченные проблемы вне скоупа

- **Нет способа сменить пароль.** `users:create` только создаёт (запрещено перезаписывать), сброса пароля нет — при утере пароля администратор правит БД руками. Нужна отдельная команда.
- **Лимит входа по IP за прокси.** Ключ лимитера — email + IP; за обратным прокси без настроенных trusted proxies все клиенты «сольются» в IP прокси.
- **Роли не различаются:** любой вошедший пользователь видит все standalone-страницы.
- **Сообщения об ошибках входа на английском** («These credentials do not match our records.»): локаль приложения — `en`, русской локализации нет; тесты текст не проверяют.
- **Стартовая страница `/`** — по-прежнему демо-страница фреймворка (с брендингом Laravel), не редиректит на дашборд.
- **`PUT /storage/{path}`** (служебный роут диска `local`) публичен по умолчанию (защищён подписью фреймворка) — не проверял, не менял.
- **Turnover остаётся упрощённым:** штуки (не деньги), среднее по двум точкам, неполные первый/последний месяцы диапазона не нормируются — по заданию не менялось.
  Корректность теперь зависит от согласованности `fetchStock` и `fetchStockMovements` у адаптера.
- **jsonb не сохраняет порядок ключей** `value_meta` (проверки в тестах учитывают).
- **Значения turnover в уже существующих БД остаются старыми до пересчёта** `metrics:calculate` за нужные месяцы (dev-БД не трогалась).

## 9. Открытые вопросы

1. Нужна ли команда смены пароля (`users:password`) и/или простая процедура сброса администратором?
2. `/demo/widgets`: оставить под `auth` (как сейчас) или удалить демо-страницу совсем?
3. Редиректить `/` на дашборд (и убрать стартовую страницу фреймворка)?
4. Нужна ли русская локализация сообщений входа?
5. Нужно ли различать роли или достаточно «вошёл — видит всё»?
6. Что нужно сделать на dev-БД и я не делал: пересчитать turnover (`metrics:calculate`) и создать первого пользователя (`users:create`); миграций для этого не требуется
   (таблицы `users` и `sessions` уже входят в существующую миграцию).
