@php
    /** @var \App\Core\Widgets\DTO\ProductCardData $card */
    $num = fn (?float $v, int $p = 2) => $v === null ? '—' : \App\Core\Support\Format::num($v, $p);
    $month = fn (string $key) => substr($key, strpos($key, ':') + 1);
    $showTurnover = (bool) config('analytics.features.turnover');
    $delta = $card->revenueDeltaPercent();
@endphp
<x-layouts.standalone :title="$card->productName">
    <h1>{{ $card->productName }}</h1>
    <p>
        Код: {{ $card->productId }}
        · Категория: {{ $card->category === null ? 'без категории' : \App\Core\Widgets\CategoryProvider::label($card->category) }}
        · ABC/XYZ:
        @if ($card->abcClass === null && $card->xyzClass === null)
            —
        @else
            <strong>{{ $card->abcClass ?? '?' }}{{ $card->xyzClass ?? '?' }}</strong> (расчёт за {{ $month($card->abcXyzPeriod ?? '') }})
        @endif
    </p>

    <x-widgets.month-switcher :nav="$monthNav" />

    @if ($card->period === null)
        <p>Нет данных: расчёт метрик ещё не выполнялся.</p>
    @else
        <div class="widget widget-product-month">
            <h2>Показатели за {{ $month($card->period) }}</h2>
            <table>
                <tbody>
                    <tr>
                        <th>Выручка</th>
                        <td>
                            {{ $num($card->month?->revenue) }}
                            @if ($delta !== null)
                                <span class="{{ $delta >= 0 ? 'kpi-delta-up' : 'kpi-delta-down' }}">({{ $delta >= 0 ? '+' : '' }}{{ $num($delta) }}% к пред. месяцу)</span>
                            @endif
                        </td>
                    </tr>
                    @if ($showTurnover)
                        <tr><th>Продано, шт.</th><td>{{ $num($card->month?->unitsSold) }}</td></tr>
                        <tr><th>Остаток на конец месяца, шт.</th><td>{{ $num($card->month?->closingStock) }}</td></tr>
                        <tr><th>Оборачиваемость</th><td>{{ $num($card->month?->turnover) }}</td></tr>
                    @endif
                    @if (config('analytics.features.dead_stock'))
                        <tr>
                            <th>Дней без продаж</th>
                            <td>
                                @if ($card->daysSinceLastSale === null)
                                    — (нет остатка)
                                @else
                                    {{ $card->noSalesInLookback ? 'не меньше ' : '' }}{{ $num($card->daysSinceLastSale, 0) }}
                                    @if ($card->daysSinceLastSale >= $deadStockDays)
                                        <strong>— неликвид</strong> (порог {{ $deadStockDays }} дн.)
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endif
                    @if ($card->lostSales !== null)
                        <tr>
                            <th>Потерянные продажи (эвристика)</th>
                            <td>продаж нет; выручка за {{ $month($card->lostSalesFrom ?? '') }} была {{ $num($card->lostSales) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if ($revenueChart !== null)
            <x-widgets.line-chart :data="$revenueChart" />
        @endif

        <div class="widget widget-product-history">
            <h2>По месяцам</h2>
            <p>Месяцы, за которые выполнялся расчёт; 0 — продаж товара в месяце не было.</p>
            <table>
                <thead>
                    <tr>
                        <th>Месяц</th>
                        <th>Выручка</th>
                        @if ($showTurnover)
                            <th>Продано, шт.</th>
                            <th>Остаток на конец, шт.</th>
                            <th>Оборачиваемость</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($card->history as $row)
                        <tr>
                            <td>{{ $month($row->period) }}</td>
                            <td>{{ $num($row->revenue) }}</td>
                            @if ($showTurnover)
                                <td>{{ $num($row->unitsSold) }}</td>
                                <td>{{ $num($row->closingStock) }}</td>
                                <td>{{ $num($row->turnover) }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $showTurnover ? 5 : 2 }}">Нет данных за период</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (config('analytics.features.stockout_risk'))
            <div class="widget widget-product-warehouses">
                <h2>Склады</h2>
                <p>Остаток на конец месяца и дни до обнуления при текущей скорости продаж; риск дефицита — не больше {{ $stockoutRiskDays }} дн.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Склад</th>
                            <th>Остаток, шт.</th>
                            <th>Продаж в день, шт.</th>
                            <th>Дней до обнуления</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($card->warehouses as $row)
                            <tr>
                                <td>{{ $row->warehouseName }}</td>
                                <td>{{ $num($row->stockQty) }}</td>
                                <td>{{ $num($row->dailyRate) }}</td>
                                <td>
                                    @if ($row->daysOfStock === null)
                                        нет продаж
                                    @else
                                        {{ $num($row->daysOfStock, 1) }}
                                        @if ($row->daysOfStock <= $stockoutRiskDays)
                                            <strong>— риск дефицита</strong>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Нет остатков и продаж по складам за период</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-layouts.standalone>
