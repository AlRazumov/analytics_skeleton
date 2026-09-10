@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\LineChartData $data */
    $canvasId = 'line-chart-'.\Illuminate\Support\Str::random(8);
    $labels = array_map(fn ($point) => $point->label, $data->series[0]->points ?? []);
    $datasets = array_map(fn ($series) => [
        'label' => $series->name,
        'data' => array_map(fn ($point) => $point->value, $series->points),
    ], $data->series);
@endphp

<div class="widget widget-line-chart">
    <h3>{{ $data->title }}</h3>
    <canvas id="{{ $canvasId }}"></canvas>
</div>

<script>
    (function () {
        const ctx = document.getElementById(@json($canvasId));
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($labels),
                datasets: @json($datasets),
            },
        });
    })();
</script>
