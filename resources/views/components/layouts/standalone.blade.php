@props(['title' => 'Аналитика'])
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Аналитика</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: sans-serif;
            margin: 0;
            color: #222;
            background: #f7f7f8;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.75rem 1.5rem;
            background: #1f2937;
            color: #fff;
        }
        .header__brand {
            font-weight: bold;
            font-size: 1.1rem;
        }
        .header__nav {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .header__nav a {
            color: #d1d5db;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .header__nav a:hover,
        .header__nav a.is-active {
            color: #fff;
            text-decoration: underline;
        }
        .header__user-slot {
            min-width: 2rem;
            min-height: 2rem;
        }
        .content {
            padding: 1.5rem;
            max-width: 1000px;
            margin: 0 auto;
        }
        .footer {
            padding: 1rem 1.5rem;
            text-align: center;
            color: #6b7280;
            font-size: 0.85rem;
        }
        .widget { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; background: #fff; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
        .kpi-value { font-size: 2rem; font-weight: bold; }
        .kpi-delta-up { color: green; }
        .kpi-delta-down { color: crimson; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header__brand">Аналитика</div>
        {{--
            Пункты навигации захардкожены прямо здесь — осознанно для
            этапа 05 (простой статичный список, без auth/ролей/клиентской
            конфигурации). Когда появится 3-й+ пункт меню или навигация
            станет зависеть от клиента/конфигурации — вынести в конфиг
            или view-composer, а не разрастать список тут.
        --}}
        <nav class="header__nav">
            <a href="{{ route('dashboards.overview') }}" @class(['is-active' => request()->routeIs('dashboards.overview')])>Обзор продаж</a>
            <a href="{{ route('dashboards.abc-xyz') }}" @class(['is-active' => request()->routeIs('dashboards.abc-xyz')])>ABC/XYZ-анализ</a>
        </nav>
        {{-- Место под будущий блок пользователя (авторизация — вне рамок этапа 5) --}}
        <div class="header__user-slot"></div>
    </header>

    <main class="content">
        {{ $slot }}
    </main>

    <footer class="footer">
        Analytics Skeleton — демо-каркас, данные из MockAdapter
    </footer>
</body>
</html>
