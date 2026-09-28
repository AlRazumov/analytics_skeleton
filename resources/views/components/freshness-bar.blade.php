@php
    /** @var \App\Services\DataFreshness $freshness */
    $success = $freshness->lastSuccess;
    $attempt = $freshness->laterAttempt;
@endphp
@if ($success !== null || $attempt !== null)
    <div @class(['freshness-bar', 'freshness-bar--warning' => $freshness->stale || $attempt?->status === \App\Models\MetricsRun::FAILED])>
        @if ($success !== null)
            Данные рассчитаны {{ $time($success->finished_at) }} за {{ $month($success->period_start) }} — {{ $month($success->period_end) }}.
        @else
            Успешных расчётов ещё не было.
        @endif
        @if ($attempt?->status === \App\Models\MetricsRun::FAILED)
            Последний расчёт ({{ $time($attempt->started_at) }}) завершился ошибкой@if ($success !== null) — показаны данные предыдущего@endif.
        @elseif ($attempt?->status === \App\Models\MetricsRun::RUNNING)
            Идёт расчёт (начат {{ $time($attempt->started_at) }}).
        @endif
        @if ($freshness->stale)
            Данные старше {{ $staleAfterHours }} ч — проверьте ночной расчёт.
        @endif
    </div>
@endif
