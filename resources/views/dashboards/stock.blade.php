<x-layouts.standalone title="Остатки">
    <h1>Остатки</h1>

    @if ($deadStock !== null)
        <x-widgets.dead-stock-table :data="$deadStock" :threshold-days="$deadStockDays" />
    @endif

    @if ($stockoutRisk !== null)
        <x-widgets.stockout-risk-table :data="$stockoutRisk" :threshold-days="$stockoutRiskDays" />
    @endif
</x-layouts.standalone>
