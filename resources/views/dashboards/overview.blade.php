<x-layouts.standalone title="Обзор продаж">
    <h1>Обзор продаж</h1>

    <x-widgets.kpi-card :data="$kpiCard" />
    <x-widgets.line-chart :data="$lineChart" />
    <x-widgets.bar-chart :data="$barChart" />
    <x-widgets.table :data="$table" />
</x-layouts.standalone>
