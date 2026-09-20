@php
    use App\Core\Domain\Enums\ComparisonBase;

    $link = fn (ComparisonBase $target) => route('dashboards.top-products', array_filter([
        'period' => request()->query('period'),
        'base' => $target === ComparisonBase::Previous ? null : $target->value,
    ]));
@endphp
<x-layouts.standalone title="Топ товаров">
    <h1>Топ товаров по выручке</h1>

    @if ($yearAgoAvailable)
        <p class="base-switch">
            Сравнение:
            @if ($base === ComparisonBase::Previous)
                <strong>с предыдущим месяцем</strong> · <a href="{{ $link(ComparisonBase::YearAgo) }}">с тем же месяцем прошлого года</a>
            @else
                <a href="{{ $link(ComparisonBase::Previous) }}">с предыдущим месяцем</a> · <strong>с тем же месяцем прошлого года</strong>
            @endif
        </p>
    @endif

    @if ($baseMissing)
        <div class="widget">
            <p>Нет данных за прошлый год: за {{ substr($periodKey, strpos($periodKey, ':') + 1) }} нет ни одного товара с выручкой в том же месяце прошлого года.</p>
            <p><a href="{{ $link(ComparisonBase::Previous) }}">Показать сравнение с предыдущим месяцем</a></p>
        </div>
    @else
        @if ($topChart !== null)
            <x-widgets.bar-chart :data="$topChart" :horizontal="true" />
        @endif

        <x-widgets.top-products-table :data="$top" title="Топ" :base="$base" />
        <x-widgets.top-products-table
            :data="$antiTop"
            title="Наименьшая выручка среди проданных за месяц"
            :base="$base"
            :note="config('analytics.features.dead_stock') ? 'Товары без продаж за месяц смотрите в разделе «Неликвиды».' : null"
        />
    @endif
</x-layouts.standalone>
