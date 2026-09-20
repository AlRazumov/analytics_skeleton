<x-layouts.standalone title="Перемещения">
    <h1>Перемещения между складами</h1>
    <p>Рекомендации: откуда и сколько довезти на склады, где товар скоро закончится.</p>

    <x-widgets.transfers-table :data="$transfers" :thresholds="$thresholds" />
</x-layouts.standalone>
