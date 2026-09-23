# Отчёт: разрыв зависимости `resolvePeriod` от `MockAdapter`

Не новый этап (правка Known issue из `docs/roadmap.md`, найденного при ревью
кода 2026-09-20). Готовится перед стартом этапов 15/16 (реальные адаптеры).

## Что изменилось

- Добавлен опциональный интерфейс `App\Core\Contracts\ProvidesHistoryBounds`
  с единственным методом `historyEnd(): DateTimeImmutable`. Не входит в
  `DataSourceAdapter` — реальный источник (Bitrix24/1С) не обязан знать
  фиксированный конец своей истории (решение этапа 07 не пересматривалось).
- `MockAdapter` теперь реализует `ProvidesHistoryBounds` (метод `historyEnd()`
  уже существовал, изменилась только сигнатура интерфейса, не тело).
- `App\Console\Commands\CalculateMetrics::resolvePeriod()`: `instanceof
  MockAdapter` заменён на `instanceof ProvidesHistoryBounds`. Поведение по
  датам не изменилось — для мока (сейчас единственный источник, реализующий
  интерфейс) период по умолчанию, как и раньше, 12 месяцев, заканчивающихся
  на `historyEnd()`. Для гипотетического адаптера без этого интерфейса —
  как и раньше для «неизвестного» случая — 12 месяцев, заканчивающихся
  текущим месяцем.

## Тест

Добавлен `tests/Unit/Console/Commands/CalculateMetricsResolvePeriodTest.php`:
вызывает приватный `resolvePeriod()` через `ReflectionMethod` на
`fakeAdapter()` (тестовый хелпер из `tests/Pest.php`, не реализует
`ProvidesHistoryBounds`) — это и есть регрессионный тест на будущие
`Bitrix24Adapter`/`OneCAdapter`:

- без `--period` — период 12 месяцев, заканчивающихся текущим месяцем;
- с явным `--period` — используется он, независимо от `ProvidesHistoryBounds`.

## Прогон

```
./vendor/bin/sail artisan test
{"tool":"pest","result":"passed","tests":457,"passed":457,"assertions":95439}

./vendor/bin/sail pint --test
{"tool":"pint","result":"passed"}
```

Существующие тесты не менялись и не сломались.

## Roadmap

Пункт Known issues про `resolvePeriod`/`instanceof MockAdapter` закрыт
(см. `docs/roadmap.md`).
