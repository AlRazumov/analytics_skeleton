<x-layouts.standalone title="Топ товаров">
    <h1>Топ товаров по выручке</h1>

    <x-widgets.top-products-table :data="$top" title="Топ" />
    <x-widgets.top-products-table :data="$antiTop" title="Анти-топ" />
</x-layouts.standalone>
