@props(['data', 'thresholdDays'])
@php
    /** @var \App\Core\Widgets\DTO\RankedTableData<\App\Core\Widgets\DTO\StockoutRiskRow> $data */
@endphp

<div class="widget widget-table widget-stockout-risk">
    <h2>Риск дефицита</h2>
    <p>Пары товар × склад, где остатка хватит не более чем на {{ $thresholdDays }} дней.</p>
    <x-widgets.period-caption :data="$data" />

    @if ($data->period !== null)
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Склад</th>
                    <th>Остаток, шт.</th>
                    <th>Продаж в день, шт.</th>
                    <th>Дней до обнуления</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data->rows as $row)
                    <tr>
                        <td>{{ $row->productName }}</td>
                        <td>{{ $row->warehouseName }}</td>
                        <td>@if ($row->stockQty === null)—@else{{ \App\Support\Format::num($row->stockQty) }}@endif</td>
                        <td>@if ($row->dailyRate === null)—@else{{ \App\Support\Format::num($row->dailyRate) }}@endif</td>
                        <td>{{ \App\Support\Format::num($row->daysOfStock, 1) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Нет данных за период</td></tr>
                @endforelse
            </tbody>
        </table>
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>
    @endif
</div>
