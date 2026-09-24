@php
    /** @var \App\Core\Widgets\DTO\TopNData|null $data */
    /** @var \App\Core\Widgets\DTO\LineChartData|null $chart */
    /** @var string|null $title заголовок вместо «Топ-N: <метрика>» */
    $fmt = fn (string $key, float $v) => match ($key) {
        'sales_count' => \App\Support\Format::num($v, 0),
        'share_of_total', 'trend' => \App\Support\Format::num($v, 1).'%',
        default => \App\Support\Format::num($v),
    };
    $columns = $data === null ? [] : [$data->metric, ...$data->columns];
@endphp
@if ($data !== null)
    <div class="widget widget-table widget-top-n widget-top-n-{{ $data->entityType }}">
        <h2>{{ $title ?? 'Топ-'.$limit.': '.\App\Support\MetricLabels::label($data->metric) }}</h2>

        @if ($data->coverage === 'partial' && $data->coveragePercent !== null)
            <p class="widget-note widget-coverage">Данные по продавцам покрывают {{ round($data->coveragePercent) }}% продаж</p>
        @endif

        <p>Период: {{ substr($data->period, strpos($data->period, ':') + 1) }}</p>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Название</th>
                    @foreach ($columns as $column)
                        <th>{{ \App\Support\MetricLabels::label($column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($data->rows as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $row->name }}</td>
                        @foreach ($columns as $column)
                            <td>{{ $fmt($column, $column === $data->metric ? $row->value : ($row->extras[$column] ?? 0.0)) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($data->unassigned !== null)
                    <tr class="row-unassigned">
                        <td>—</td>
                        <td>{{ $data->unassigned->name }}</td>
                        @foreach ($columns as $column)
                            <td>{{ $fmt($column, $column === $data->metric ? $data->unassigned->value : ($data->unassigned->extras[$column] ?? 0.0)) }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>

        <x-widgets.bar-chart :data="$chart" :horizontal="true" />
    </div>
@endif
