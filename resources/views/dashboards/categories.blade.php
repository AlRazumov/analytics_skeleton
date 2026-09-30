@php
    use App\Core\Domain\Enums\ComparisonBase;
    use App\Core\Support\Format;

    $link = fn (ComparisonBase $target) => route('dashboards.categories', array_filter([
        'period' => request()->query('period'),
        'base' => $target === ComparisonBase::Previous ? null : $target->value,
    ]));
    $yearAgo = $base === ComparisonBase::YearAgo;
    $baseColumn = $yearAgo ? 'Тот же месяц год назад' : 'Прошлый месяц';
    $deltaSuffix = $yearAgo ? 'к тому же месяцу прошлого года' : 'к пред. месяцу';
@endphp
<x-layouts.standalone title="Категории">
    <h1>Выручка по категориям</h1>

    <x-widgets.month-switcher :nav="$monthNav" />

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
            <p>Нет данных за прошлый год: за {{ substr($periodKey, strpos($periodKey, ':') + 1) }} нет ни одной категории с выручкой в том же месяце прошлого года.</p>
            <p><a href="{{ $link(ComparisonBase::Previous) }}">Показать сравнение с предыдущим месяцем</a></p>
        </div>
    @else
        @if ($revenueChart !== null)
            <x-widgets.bar-chart :data="$revenueChart" :horizontal="true" />
        @endif

        <div class="widget widget-table widget-categories">
            <h2>Категории</h2>
            <x-widgets.period-caption :data="$table" />

            @if ($table->period !== null)
                <table>
                    <thead>
                        <tr>
                            <th>Категория</th>
                            <th>Выручка</th>
                            <th>Доля, %</th>
                            <th>{{ $baseColumn }}</th>
                            <th>Изменение, {{ $deltaSuffix }}</th>
                            <th>Изменение, % {{ $deltaSuffix }}</th>
                            <th>Продано товаров</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($table->rows as $row)
                            @php
                                $class = $row->deltaAbs === null || $row->deltaAbs == 0 ? '' : ($row->deltaAbs > 0 ? 'kpi-delta-up' : 'kpi-delta-down');
                                $arrow = $row->deltaAbs === null || $row->deltaAbs == 0 ? '' : ($row->deltaAbs > 0 ? '▲ ' : '▼ ');
                            @endphp
                            <tr>
                                <td>
                                    @if (config('analytics.features.top_products'))
                                        <a href="{{ route('dashboards.top-products', array_filter([
                                            'period' => $table->period,
                                            'base' => $yearAgo ? $base->value : null,
                                            'category' => $row->categoryId,
                                        ])) }}">{{ $row->categoryName }}</a>
                                    @else
                                        {{ $row->categoryName }}
                                    @endif
                                </td>
                                <td>{{ Format::num($row->value) }}</td>
                                <td>@if ($row->sharePct === null)—@else{{ Format::num($row->sharePct, 1) }}@endif</td>
                                <td>@if ($row->baseValue === null)—@else{{ Format::num($row->baseValue) }}@endif</td>
                                <td class="{{ $class }}">@if ($row->deltaAbs === null)—@else{{ $arrow }}{{ Format::num($row->deltaAbs) }}@endif</td>
                                <td class="{{ $class }}">@if ($row->deltaPct === null)—@else{{ $arrow }}{{ Format::num($row->deltaPct) }}%@endif</td>
                                <td>{{ $row->productsSold ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7">Нет данных за период</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p><a href="{{ route('dashboards.categories.export', array_filter([
                    'period' => $table->period,
                    'base' => $yearAgo ? $base->value : null,
                ])) }}">Скачать CSV</a></p>
            @endif
        </div>
    @endif

    @if ($monthlyChart !== null)
        <x-widgets.line-chart :data="$monthlyChart" />
    @endif
</x-layouts.standalone>
