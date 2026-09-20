<x-layouts.standalone title="Топ товаров">
    <h1>Топ товаров по выручке</h1>

    @if ($topChart !== null)
        <x-widgets.bar-chart :data="$topChart" :horizontal="true" />
    @endif

    <x-widgets.top-products-table :data="$top" title="Топ" />
    <x-widgets.top-products-table
        :data="$antiTop"
        title="Наименьшая выручка среди проданных за месяц"
        :note="config('analytics.features.dead_stock') ? 'Товары без продаж за месяц смотрите в разделе «Неликвиды».' : null"
    />
</x-layouts.standalone>
