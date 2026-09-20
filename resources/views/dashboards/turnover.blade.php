<x-layouts.standalone title="Оборачиваемость">
    <h1>Оборачиваемость</h1>
    <p>Продано за месяц / средний остаток за месяц, в штуках («раз за месяц»).</p>

    @if ($distribution !== null)
        <x-widgets.bar-chart :data="$distribution" />
    @endif

    <x-widgets.turnover-table :data="$lowest" title="Самая низкая оборачиваемость" description="Товары с наименьшим отношением проданного к среднему остатку; 0 — остаток есть, продаж не было." />
    <x-widgets.turnover-table :data="$highest" title="Самая высокая оборачиваемость" description="Товары, которые продаются быстрее всего относительно остатка." />
</x-layouts.standalone>
