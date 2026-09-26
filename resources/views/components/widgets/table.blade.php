@props(['data', 'title' => null])
@php
    /** @var \App\Core\Widgets\DTO\TableData $data */
@endphp

<div class="widget widget-table">
    @if ($title !== null)
        <h2>{{ $title }}</h2>
    @endif
    <table>
        <thead>
            <tr>
                @foreach ($data->headers as $header)
                    <th>{{ \App\Support\MetricLabels::label($header) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data->rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ is_int($cell) || is_float($cell) ? \App\Support\Format::num($cell) : $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($data->headers) }}">Нет данных</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if ($data->total !== null && $data->rows !== [])
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>
    @endif
    {{ $slot }}
</div>
