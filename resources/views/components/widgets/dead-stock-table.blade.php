@props(['data', 'thresholdDays'])
@php
    /** @var \App\Core\Widgets\DTO\RankedTableData<\App\Core\Widgets\DTO\DeadStockRow> $data */
@endphp

<div class="widget widget-table widget-dead-stock">
    <h2>Неликвиды</h2>
    <p>Товары без продаж {{ $thresholdDays }} дней и более, остаток на конец периода положительный.</p>
    <x-widgets.period-caption :data="$data" />

    @if ($data->period !== null)
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Остаток, шт.</th>
                    <th>Дней без продаж</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data->rows as $row)
                    <tr>
                        <td>{{ $row->productName }}</td>
                        <td>@if ($row->stockQty === null)—@else{{ \App\Support\Format::num($row->stockQty) }}@endif</td>
                        <td>{{ $row->lowerBound ? '≥ ' : '' }}{{ \App\Support\Format::num($row->daysSinceLastSale, 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Нет данных за период</td></tr>
                @endforelse
            </tbody>
        </table>
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>
    @endif
</div>
