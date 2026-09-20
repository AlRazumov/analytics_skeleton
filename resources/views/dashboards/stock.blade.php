<x-layouts.standalone title="Остатки">
    <h1>Остатки</h1>

    @if ($deadStockChart !== null)
        <x-widgets.bar-chart :data="$deadStockChart" note="Товары без продаж за весь просмотренный период (нет ни одной продажи) отнесены к корзине по нижней границе возраста." />
    @endif
    @if ($deadStock !== null)
        <x-widgets.dead-stock-table :data="$deadStock" :threshold-days="$deadStockDays" />
    @endif

    @if ($daysOfStockChart !== null)
        <x-widgets.bar-chart :data="$daysOfStockChart" note="Подписи корзин — по полным дням: «0–7» включает значения от 0 до 8 дней, не включая 8." />
    @endif
    @if ($stockoutRisk !== null)
        <x-widgets.stockout-risk-table :data="$stockoutRisk" :threshold-days="$stockoutRiskDays" />
    @endif
</x-layouts.standalone>
