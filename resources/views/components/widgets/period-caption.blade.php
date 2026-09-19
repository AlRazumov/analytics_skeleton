@props(['data'])
@php
    /** @var \App\Core\Widgets\DTO\RankedTableData $data */
@endphp
{{-- Пустые состояния таблиц: нет снэпшотов метрики / нет данных за период. --}}
@if ($data->period === null)
    <p>Нет данных: расчёт метрики ещё не выполнялся.</p>
@else
    <p>Период: {{ substr($data->period, strpos($data->period, ':') + 1) }}</p>
@endif
