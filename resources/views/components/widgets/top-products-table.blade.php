@props(['data', 'title', 'note' => null])
@php
    /** @var \App\Core\Widgets\DTO\RankedTableData<\App\Core\Widgets\DTO\TopProductRow> $data */
@endphp

<div class="widget widget-table widget-top-products">
    <h2>{{ $title }}</h2>
    @if ($note !== null)
        <p class="widget-note">{{ $note }}</p>
    @endif
    <x-widgets.period-caption :data="$data" />

    @if ($data->period !== null)
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Выручка</th>
                    <th>Прошлый месяц</th>
                    <th>Изменение</th>
                    <th>Изменение, %</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data->rows as $row)
                    <tr>
                        <td>{{ $row->productName }}</td>
                        <td>{{ \App\Support\Format::num($row->value) }}</td>
                        <td>@if ($row->baseValue === null)—@else{{ \App\Support\Format::num($row->baseValue) }}@endif</td>
                        @php
                            $class = $row->deltaAbs === null || $row->deltaAbs == 0 ? '' : ($row->deltaAbs > 0 ? 'kpi-delta-up' : 'kpi-delta-down');
                            $arrow = $row->deltaAbs === null || $row->deltaAbs == 0 ? '' : ($row->deltaAbs > 0 ? '▲ ' : '▼ ');
                        @endphp
                        <td class="{{ $class }}">@if ($row->deltaAbs === null)—@else{{ $arrow }}{{ \App\Support\Format::num($row->deltaAbs) }}@endif</td>
                        <td class="{{ $class }}">@if ($row->deltaPct === null)—@else{{ $arrow }}{{ \App\Support\Format::num($row->deltaPct) }}%@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Нет данных за период</td></tr>
                @endforelse
            </tbody>
        </table>
        <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>
    @endif
</div>
