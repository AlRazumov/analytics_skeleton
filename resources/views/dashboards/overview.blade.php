<x-layouts.standalone title="Обзор продаж">
    <h1>Обзор продаж</h1>

    @if ($empty)
        <div class="widget">
            <p>Нет данных: расчёт метрик ещё не выполнялся.</p>
        </div>
    @else
        <p>Окно: 6 месяцев по {{ substr($periodKey, strpos($periodKey, ':') + 1) }}</p>

        <x-widgets.kpi-card :data="$kpiCard" />
        <x-widgets.line-chart :data="$lineChart" />
        <x-widgets.bar-chart :data="$barChart" />
        <x-widgets.table :data="$table" />

        @if (config('analytics.features.sellers'))
            <x-widgets.top-n entity-type="seller" metric="sales_count" :limit="3" :period="$period" />
        @endif
    @endif
</x-layouts.standalone>
