@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\KpiCardData $data */
@endphp

<div class="widget widget-kpi-card">
    <div class="kpi-label">{{ $data->label }}</div>
    <div class="kpi-value">
        {{ number_format($data->value, 2) }}
        @if ($data->unit)
            <span class="kpi-unit">{{ $data->unit }}</span>
        @endif
    </div>
    @if ($data->deltaPercent !== null)
        <div class="kpi-delta {{ $data->deltaPercent >= 0 ? 'kpi-delta-up' : 'kpi-delta-down' }}">
            {{ $data->deltaPercent >= 0 ? '+' : '' }}{{ number_format($data->deltaPercent, 2) }}%
        </div>
    @endif
</div>
