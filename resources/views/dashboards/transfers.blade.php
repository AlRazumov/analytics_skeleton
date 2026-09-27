<x-layouts.standalone title="Перемещения">
    <h1>Перемещения между складами</h1>
    <p>Рекомендации: откуда и сколько довезти на склады, где товар скоро закончится.</p>

    <x-widgets.transfers-table :data="$transfers" :thresholds="$thresholds" />
    @if ($transfers->hasData && $transfers->total > 0)
        <p><a href="{{ route('dashboards.transfers.export', ['period' => $transfers->period]) }}">Скачать CSV</a> — все рекомендации за месяц.</p>
    @endif
</x-layouts.standalone>
