@php
    use App\Support\MetricLabels;

    $link = fn (string $route, string $target) => route($route, array_filter([
        'period' => request()->query('period'),
        'metric' => $target,
    ]));
@endphp
<x-layouts.standalone title="Продавцы">
    <h1>Продавцы</h1>

    @if ($metric !== null && count($metrics) > 1)
        <p class="base-switch">
            Ранжировать по:
            @foreach ($metrics as $key)
                @if ($key === $metric)
                    <strong>{{ MetricLabels::label($key) }}</strong>
                @else
                    <a href="{{ $link('dashboards.sellers', $key) }}">{{ MetricLabels::label($key) }}</a>
                @endif
                @unless ($loop->last) · @endunless
            @endforeach
        </p>
    @endif

    @if ($data === null)
        <div class="widget">
            <p>Нет данных по продавцам за выбранный период.</p>
            <p class="widget-note">Если источник не передаёт продавца в продажах, разбивка по продавцам недоступна.</p>
        </div>
    @else
        @include('components.widgets.top-n', [
            'data' => $data,
            'chart' => $chart,
            'limit' => count($data->rows),
            'title' => 'Рейтинг: '.MetricLabels::label($data->metric),
        ])
        <p><a href="{{ $link('dashboards.sellers.export', $metric) }}">Скачать CSV</a></p>
    @endif
</x-layouts.standalone>
