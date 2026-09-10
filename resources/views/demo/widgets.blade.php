<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Widgets demo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        body { font-family: sans-serif; margin: 2rem; }
        .widget { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 2rem; max-width: 800px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
        .kpi-value { font-size: 2rem; font-weight: bold; }
        .kpi-delta-up { color: green; }
        .kpi-delta-down { color: crimson; }
    </style>
</head>
<body>
    <h1>Widgets demo (MockAdapter → metrics_snapshots)</h1>

    <x-widgets.kpi-card :data="$kpiCard" />
    <x-widgets.line-chart :data="$lineChart" />
    <x-widgets.bar-chart :data="$barChart" />
    <x-widgets.table :data="$table" />
    <x-widgets.matrix :data="$matrix" />
</body>
</html>
