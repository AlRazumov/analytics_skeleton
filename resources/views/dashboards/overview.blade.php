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
        <x-widgets.bar-chart :data="$barChart" :note="count($barChart->series) < 2 ? 'Сравнение с прошлым годом недоступно: в истории нет данных за тот же период прошлого года.' : null" />
        <x-widgets.table :data="$table" title="Товары с наибольшей выручкой за окно">
            @if (config('analytics.features.top_products'))
                <p><a href="{{ route('dashboards.top-products', ['period' => $periodKey]) }}">Топ товаров за месяц со сравнением</a></p>
            @endif
        </x-widgets.table>

        @if (config('analytics.features.sellers'))
            <x-widgets.top-n entity-type="seller" metric="sales_count" :limit="3" :period="$period" />
        @endif
    @endif
</x-layouts.standalone>
