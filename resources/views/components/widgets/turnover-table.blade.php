@props(['data', 'title', 'description'])
@php
    /** @var \App\Core\Widgets\DTO\RankedTableData<\App\Core\Widgets\DTO\TurnoverRow> $data */
@endphp

<div class="widget widget-table widget-turnover">
    <h2>{{ $title }}</h2>
    <p>{{ $description }}</p>
    <x-widgets.period-caption :data="$data" />

    @if ($data->period !== null)
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Остаток на конец месяца, шт.</th>
                    <th>Продано, шт.</th>
                    <th>Оборачиваемость</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data->rows as $row)
                    <tr>
                        <td>{{ $row->productName }}</td>
                        <td>@if ($row->closingStock === null)—@else{{ \App\Support\Format::num($row->closingStock) }}@endif</td>
                        <td>@if ($row->unitsSold === null)—@else{{ \App\Support\Format::num($row->unitsSold) }}@endif</td>
                        <td>{{ \App\Support\Format::num($row->turnover) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Нет данных за период</td></tr>
                @endforelse
            </tbody>
        </table>
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>
    @endif
</div>
