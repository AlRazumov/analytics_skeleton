@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\KpiCardData $data */
@endphp

<div class="widget widget-kpi-card">
    <div class="kpi-label">{{ \App\Support\MetricLabels::label($data->label) }}</div>
    <div class="kpi-value">
        {{ \App\Support\Format::num($data->value) }}
        @if ($data->unit)
            <span class="kpi-unit">{{ $data->unit }}</span>
        @endif
    </div>
    @if ($data->deltaPercent !== null)
        <div class="kpi-delta {{ $data->deltaPercent >= 0 ? 'kpi-delta-up' : 'kpi-delta-down' }}">
            {{ $data->deltaPercent >= 0 ? '+' : '' }}{{ \App\Support\Format::num($data->deltaPercent) }}%
        </div>
    @endif
</div>
