@props(['nav'])
@php
    /** @var \App\Core\Widgets\DTO\MonthNavigation $nav */
    $label = fn (string $key) => substr($key, strpos($key, ':') + 1);
@endphp
{{-- Переключатель месяцев: ближайшие месяцы с данными страницы; остальные параметры запроса (category, base, metric) сохраняются. --}}
@if ($nav->current !== null)
    <nav class="month-switcher" aria-label="Месяц">
        @if ($nav->previous !== null)
            <a href="{{ request()->fullUrlWithQuery(['period' => $nav->previous]) }}" rel="prev">&larr; {{ $label($nav->previous) }}</a>
        @endif
        <strong>{{ $label($nav->current) }}</strong>
        @if ($nav->next !== null)
            <a href="{{ request()->fullUrlWithQuery(['period' => $nav->next]) }}" rel="next">{{ $label($nav->next) }} &rarr;</a>
        @endif
    </nav>
@endif
