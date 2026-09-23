@props(['data', 'thresholds'])
@php
    /** @var \App\Core\Widgets\DTO\TransferTableData $data */
    $num = fn (float|int $v, int $p = 1) => \App\Support\Format::num($v, $p);
    // ЭВРИСТИКА ДЛЯ ДЕМО: у донора без продаж (stock_surplus) нет скорости
    // продаж — покрытие условно бесконечно (INF), в таблице показываем «—».
    $coverage = fn (float $v, int $p = 1) => is_infinite($v) ? '—' : $num($v, $p);
    $donorLabel = fn (\App\Core\Transfers\TransferDonorReason $r) => match ($r) {
        \App\Core\Transfers\TransferDonorReason::Turnover => 'по обороту',
        \App\Core\Transfers\TransferDonorReason::StockSurplus => 'по остатку (без продаж)',
    };
@endphp

<div class="widget widget-table widget-transfers">
    <h2>Рекомендации перемещений</h2>

    @if ($data->period === null)
        <p>Нет данных: расчёт метрики ещё не выполнялся.</p>
    @else
        <p>Период: {{ substr($data->period, strpos($data->period, ':') + 1) }}</p>

        @if (! $data->hasData)
            <p>Нет данных за период</p>
        @else
            <ul>
                <li>Дефицитных пар (товар × склад): {{ $data->deficitPairs }}</li>
                <li>Для скольких есть рекомендация: {{ $data->deficitPairs - $data->unmatchedDeficits }}</li>
                <li>Без донора: {{ $data->unmatchedDeficits }}</li>
            </ul>

            <table>
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Откуда</th>
                        <th>Донор</th>
                        <th>Куда</th>
                        <th>Количество, шт.</th>
                        <th>Покрытие «откуда», дней (до → после)</th>
                        <th>Покрытие «куда», дней (до → после)</th>
                        <th>Продаж «куда» в день, шт.</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data->rows as $row)
                        <tr>
                            <td>{{ $row->productName }}</td>
                            <td>{{ $row->fromWarehouseName }}</td>
                            <td>{{ $donorLabel($row->donorReason) }}</td>
                            <td>{{ $row->toWarehouseName }}</td>
                            <td>{{ $num($row->quantity, 0) }}</td>
                            <td>{{ $coverage($row->fromCoverageBefore) }} → {{ $coverage($row->fromCoverageAfter) }}</td>
                            <td>{{ $num($row->toCoverageBefore) }} → {{ $num($row->toCoverageAfter) }}</td>
                            <td>{{ $num($row->toDailyRate, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Нет рекомендаций за период</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p>Показано {{ count($data->rows) }} из {{ $data->total }}</p>

            <p>
                Покрытие = остаток / средние продажи в день. Дефицит: покрытие не больше {{ $thresholds['deficit_days'] }} дн.;
                довозим до {{ $thresholds['target_days'] }} дн. Донор: покрытие не меньше {{ $thresholds['surplus_days'] }} дн.;
                после перемещения у донора остаётся не меньше {{ $thresholds['keep_days'] }} дн. Минимальное количество — {{ $thresholds['min_quantity'] }} шт.
                Расчёт на конец периода, не учитывает сроки доставки и сезонность.
            </p>
        @endif
    @endif
</div>
