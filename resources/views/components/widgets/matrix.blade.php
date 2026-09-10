@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\MatrixData $data */
    $cellByKey = [];
    foreach ($data->cells as $cell) {
        $cellByKey[$cell->rowKey.'|'.$cell->colKey] = $cell;
    }
@endphp

<div class="widget widget-matrix">
    <table>
        <thead>
            <tr>
                <th></th>
                @foreach ($data->colLabels as $colLabel)
                    <th>{{ $colLabel }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data->rowLabels as $rowLabel)
                <tr>
                    <th>{{ $rowLabel }}</th>
                    @foreach ($data->colLabels as $colLabel)
                        @php $cell = $cellByKey[$rowLabel.'|'.$colLabel] ?? null; @endphp
                        <td>
                            @if ($cell)
                                {{ $cell->itemsCount }} / {{ number_format($cell->value, 2) }}
                            @else
                                &mdash;
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
