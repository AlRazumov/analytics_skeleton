# Этап 05: Presentation (StandaloneLayout)

## Что делаем

Тонкий presentation-слой поверх уже готовых widgets (этап 3) — только
компоновка/каркас, без бизнес-логики и без обращений к adapters/core
мимо WidgetDataProvider, которым уже пользуются виджеты.

`StandaloneLayout` — общий каркас страницы для полноценного сайта
(1С-кейс): шапка (бренд, статичная навигация, слот-заглушка под
будущий user-блок), content-слот для дашбордов, футер.

`IframeLayout` — не в этом этапе, ждёт доступа к Б24-порталу (см.
roadmap).

## Что входит

1. Blade-layout `resources/views/components/layouts/standalone.blade.php`
   (используется как `<x-layouts.standalone>`).
2. `.header__user-slot` — пустой div в шапке, без логики авторизации.
3. Две демо-страницы дашбордов на этом layout:
   - `dashboards/overview` — обзор продаж (kpi-card, line-chart,
     bar-chart YoY, table) — та же связка виджетов, что и в
     `demo/widgets`, но на StandaloneLayout.
   - `dashboards/abc-xyz` — ABC/XYZ-анализ (matrix).
4. Статичная навигация (2 пункта) в шапке layout — без auth/ролей.

## Что явно не входит

- Аутентификация в любом виде.
- IframeLayout.
- Multi-tenant.
- Дизайн сверх минимально приличного вида.

См. отчёт: `docs/reports/stage-05-report.md`.
