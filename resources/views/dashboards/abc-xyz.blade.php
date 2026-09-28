<x-layouts.standalone title="ABC/XYZ-анализ">
    <h1>ABC/XYZ-анализ</h1>

    <x-widgets.category-filter :options="$categoryOptions" :selected="$category" />

    @if ($category !== null)
        <p class="widget-note">Классы ABC/XYZ посчитаны по всему ассортименту; показаны только товары выбранной категории.</p>
    @endif

    @if ($category !== null && $matrix->cells === [])
        <p>В этой категории нет товаров с классификацией за последний период.</p>
    @else
        <x-widgets.matrix :data="$matrix" />
    @endif
</x-layouts.standalone>
