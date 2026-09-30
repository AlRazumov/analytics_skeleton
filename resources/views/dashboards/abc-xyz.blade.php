<x-layouts.standalone title="ABC/XYZ-анализ">
    <h1>ABC/XYZ-анализ</h1>

    <x-widgets.category-filter :options="$categoryOptions" :selected="$category" />

    @if ($category !== null)
        <p class="widget-note">Классы ABC/XYZ посчитаны по всему ассортименту; показаны только товары выбранной категории.</p>
    @endif

    @if ($matrix->cells === [])
        <div class="widget">
            <p>{{ $category !== null ? 'В этой категории нет товаров с классификацией за последний период.' : 'Нет данных: расчёт метрик ещё не выполнялся.' }}</p>
        </div>
    @else
        <x-widgets.matrix :data="$matrix" />
        <p class="widget-note">
            В ячейке: число товаров / их выручка за период.
            ABC — вклад в выручку (A — товары, дающие первые 80%, B — следующие 15%, C — остальные).
            XYZ — стабильность спроса по месяцам (X — колебания до 10%, Y — до 25%, Z — больше).
        </p>
    @endif
</x-layouts.standalone>
