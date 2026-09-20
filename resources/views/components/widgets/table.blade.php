@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\TableData $data */
@endphp

<div class="widget widget-table">
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
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($data->headers) }}">Нет данных</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
