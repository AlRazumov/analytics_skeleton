<x-layouts.standalone title="Остатки">
    <h1>Остатки</h1>

    @if ($deadStockChart !== null)
        <x-widgets.bar-chart :data="$deadStockChart" note="Товары без продаж за весь просмотренный период (нет ни одной продажи) отнесены к корзине по нижней границе возраста." />
    @endif
    @if ($deadStock !== null)
        <x-widgets.dead-stock-table :data="$deadStock" :threshold-days="$deadStockDays" />
        @if ($deadStock->period !== null)
            <p><a href="{{ route('dashboards.stock.export.dead-stock', ['period' => $deadStock->period]) }}">Скачать CSV</a> — все неликвиды за месяц.</p>
        @endif
    @endif

    @if ($daysOfStockChart !== null)
        <x-widgets.bar-chart :data="$daysOfStockChart" note="Подписи корзин — по полным дням: «0–7» включает значения от 0 до 8 дней, не включая 8." />
    @endif
    @if ($stockoutRisk !== null)
        <x-widgets.stockout-risk-table :data="$stockoutRisk" :threshold-days="$stockoutRiskDays" />
        @if ($stockoutRisk->period !== null)
            <p><a href="{{ route('dashboards.stock.export.stockout-risk', ['period' => $stockoutRisk->period]) }}">Скачать CSV</a> — все пары с риском дефицита за месяц.</p>
        @endif
    @endif
</x-layouts.standalone>
